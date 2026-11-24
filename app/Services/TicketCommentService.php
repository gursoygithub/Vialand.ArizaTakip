<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketMute;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketCommentNotification;
use Illuminate\Support\Collection;

/**
 * Notes on a request ("Not Ekle"): stored as a history row that keeps the
 * status, and rung on the bell of everyone involved except the author and
 * people who muted the request.
 */
class TicketCommentService
{
    public function __construct(private FcmService $fcm) {}

    public function addComment(Ticket $ticket, User $by, string $note): TicketStatusHistory
    {
        $current = $ticket->status?->value;

        $history = TicketStatusHistory::create([
            'ticket_id' => $ticket->id,
            'from_status' => $current,
            'to_status' => $current,
            'changed_by' => $by->id,
            'note' => $note,
        ]);

        $participants = $this->participants($ticket, $by);
        $notification = new TicketCommentNotification($ticket, $by, $note);
        $participants->each(fn (User $user) => $user->notify(clone $notification));

        $this->fcm->sendToUsers(
            $participants->filter(fn (User $user): bool => $user->wantsNotification($notification->getNotificationType(), 'database')),
            $ticket->ticket_no.' • '.__('ui.history_note'),
            ($by->employee?->name ?? $by->name).': '.mb_substr($note, 0, 80),
            $ticket->panelUrl(),
            $by->id,
        );

        return $history;
    }

    /**
     * Requester, current assignee, CC people and everyone who has acted on
     * the request, minus the author and anyone who muted it.
     *
     * @return Collection<int, User>
     */
    private function participants(Ticket $ticket, User $author): Collection
    {
        $ids = TicketStatusHistory::query()
            ->where('ticket_id', $ticket->id)
            ->whereNotNull('changed_by')
            ->distinct()
            ->pluck('changed_by')
            ->merge([$ticket->created_by, $ticket->employee?->panelUser()?->id])
            ->merge($ticket->watchers()->get()->map(fn (Employee $watcher): ?int => $watcher->panelUser()?->id))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $id === (int) $author->id);

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->whereNotIn('id', TicketMute::query()->where('ticket_id', $ticket->id)->select('user_id'))
            ->get();
    }
}
