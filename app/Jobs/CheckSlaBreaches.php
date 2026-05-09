<?php

namespace App\Jobs;

use App\Enums\TaskStatusEnum;
use App\Events\TicketSlaBreached;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\SlaWarningNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckSlaBreaches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // The sla_breached column is the indexed source of truth for filters
        // and badges. TicketObserver::saving keeps it current on every save,
        // and this job is the periodic sweep for rows nobody touched: it
        // finds active tickets past their deadline that still carry
        // sla_breached=false, flips the flag, and fans out notifications
        // (deduped per-ticket per-day).
        //
        // Eligible: open lifecycle only — NOT on_hold (paused), NOT cancelled.
        $openStatuses = [
            TaskStatusEnum::OPEN->value,
            TaskStatusEnum::ASSIGNED->value,
            TaskStatusEnum::IN_PROGRESS->value,
            TaskStatusEnum::PENDING->value,
        ];

        // 1. Mark newly-breached tickets and notify (deduped per-day)
        Ticket::whereIn('status', $openStatuses)
            ->whereNotNull('sla_deadline')
            ->where('sla_deadline', '<', now())
            ->where('sla_breached', false)
            ->chunkById(100, function ($tickets) {
                foreach ($tickets as $ticket) {
                    $ticket->update(['sla_breached' => true]);
                    event(new TicketSlaBreached($ticket));
                    $this->notifyBreached($ticket);
                }
            });

        // 2. Send 80% SLA warning for tickets approaching deadline
        Ticket::whereIn('status', $openStatuses)
            ->whereNotNull('sla_deadline')
            ->where('sla_deadline', '>', now())
            ->where('sla_breached', false)
            ->chunkById(100, function ($tickets) {
                foreach ($tickets as $ticket) {
                    $this->maybeSendWarning($ticket);
                }
            });
    }

    private function maybeSendWarning(Ticket $ticket): void
    {
        if (!$ticket->created_at || !$ticket->sla_deadline) {
            return;
        }

        $total   = $ticket->created_at->diffInSeconds($ticket->sla_deadline);
        $elapsed = $ticket->created_at->diffInSeconds(now());

        if ($total <= 0 || ($elapsed / $total) < 0.80) {
            return;
        }

        // Daily de-dupe: don't repeat the same notification type for the same ticket today.
        if ($this->alreadySentToday($ticket, SlaWarningNotification::class)) {
            return;
        }

        $recipients = $this->slaRecipients($ticket);
        foreach ($recipients as $user) {
            $user->notify(new SlaWarningNotification($ticket));
        }

        app(\App\Services\FcmService::class)->sendToUsers(
            $recipients,
            $ticket->ticket_no . ' • ⚠️ SLA Uyarısı',
            'SLA süresi dolmak üzere.',
            url('/tickets/' . $ticket->id),
        );
    }

    private function notifyBreached(Ticket $ticket): void
    {
        if ($this->alreadySentToday($ticket, SlaBreachedNotification::class)) {
            return;
        }

        $recipients = $this->slaRecipients($ticket);
        foreach ($recipients as $user) {
            $user->notify(new SlaBreachedNotification($ticket));
        }

        app(\App\Services\FcmService::class)->sendToUsers(
            $recipients,
            $ticket->ticket_no . ' • 🚨 SLA İhlali',
            'SLA süresi doldu, acil müdahale gerekli.',
            url('/tickets/' . $ticket->id),
        );
    }

    /**
     * SLA recipients: assignee + ticket creator + group supervisor.
     *
     * When the ticket has no assignee (employee_id = null) but belongs to a
     * group, the group supervisor (groups.employee_id → Employee → User) is
     * added so the person responsible for the group is alerted even before
     * individual assignment happens. If the supervisor is also the creator,
     * unique('id') collapses them to a single notification.
     *
     * No admin fallback by design — the dashboard SLA widget surfaces
     * breaches for admins through the panel UI.
     */
    private function slaRecipients(Ticket $ticket): \Illuminate\Support\Collection
    {
        $assigneeUser = $ticket->employee?->user;
        $creator      = $ticket->created_by ? User::find($ticket->created_by) : null;

        $supervisor = null;
        if (is_null($ticket->employee_id) && $ticket->group_id) {
            $supervisor = \App\Models\Group::find($ticket->group_id)?->manager?->user;
        }

        return collect([$assigneeUser, $creator, $supervisor])
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Has a notification of this type already been sent today for this ticket?
     * Pulls today's matching notifications and inspects their data in PHP — no
     * JSON-extraction SQL functions, so portable across MySQL/SQLite.
     */
    private function alreadySentToday(Ticket $ticket, string $notificationClass): bool
    {
        $rows = DatabaseNotification::where('notifiable_type', User::class)
            ->where('type', $notificationClass)
            ->whereDate('created_at', today())
            ->pluck('data');

        foreach ($rows as $data) {
            $arr = is_array($data) ? $data : (json_decode((string) $data, true) ?? []);
            foreach ($arr['actions'] ?? [] as $action) {
                $url = $action['url'] ?? '';
                if (preg_match('#/tickets/(\d+)$#', (string) $url, $m)
                    && (int) $m[1] === (int) $ticket->id) {
                    return true;
                }
            }
        }

        return false;
    }

}
