<?php

namespace App\Jobs;

use App\Enums\TaskStatusEnum;
use App\Events\TicketSlaBreached;
use App\Models\Group;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\SlaWarningNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckSlaBreaches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $openStatuses = [
            TaskStatusEnum::OPEN->value,
            TaskStatusEnum::ASSIGNED->value,
            TaskStatusEnum::IN_PROGRESS->value,
            TaskStatusEnum::ON_HOLD->value,
            TaskStatusEnum::PENDING->value,
        ];

        // 1. Mark newly-breached tickets
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

        // Avoid duplicate warnings: skip if a warning was sent after 79% mark
        $warningAlreadySent = \Illuminate\Notifications\DatabaseNotification::where('notifiable_type', User::class)
            ->whereRaw("JSON_EXTRACT(data, '$.ticket_id') = ?", [$ticket->id])
            ->whereRaw("JSON_EXTRACT(data, '$.type') = 'sla_warning'")
            ->where('created_at', '>', $ticket->created_at->addSeconds((int)($total * 0.79)))
            ->exists();

        if ($warningAlreadySent) {
            return;
        }

        $recipients = collect();

        if ($ticket->employee_id) {
            $assignedUser = User::whereHas('employee', fn ($q) =>
                $q->where('id', $ticket->employee_id)
            )->first();

            if ($assignedUser) {
                $recipients->push($assignedUser);
            }
        }

        $supervisors = User::whereHas('roles', fn ($q) => $q->where('name', 'supervisor'))
            ->whereHas('groups', fn ($q) => $q->where('area_id', $ticket->area_id))
            ->get();

        $recipients = $recipients->merge($supervisors)->unique('id');

        foreach ($recipients as $user) {
            $user->notify(new SlaWarningNotification($ticket));
        }
    }

    private function notifyBreached(Ticket $ticket): void
    {
        $notifiables = User::whereHas('roles', fn ($q) =>
            $q->whereIn('name', ['supervisor', 'admin', 'super_admin'])
        )->whereHas('groups', fn ($q) =>
            $q->where('area_id', $ticket->area_id)
        )->get();

        if ($notifiables->isEmpty()) {
            $notifiables = User::whereHas('roles', fn ($q) =>
                $q->whereIn('name', ['admin', 'super_admin'])
            )->get();
        }

        foreach ($notifiables as $user) {
            $user->notify(new SlaBreachedNotification($ticket));
        }
    }
}
