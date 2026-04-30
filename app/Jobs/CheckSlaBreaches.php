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

        // Suppress duplicate warnings: skip if a SlaWarningNotification has already
        // been written for this ticket since the 80%-elapsed mark.
        $warningWindowStart = $ticket->created_at->copy()->addSeconds((int) ($total * 0.79));

        $warningAlreadySent = DatabaseNotification::where('notifiable_type', User::class)
            ->where('type', SlaWarningNotification::class)
            ->where('created_at', '>', $warningWindowStart)
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(data, '$.actions[0].url')) LIKE ?",
                ['%/tickets/' . $ticket->id]
            )
            ->exists();

        if ($warningAlreadySent) {
            return;
        }

        foreach ($this->warningRecipients($ticket) as $user) {
            $user->notify(new SlaWarningNotification($ticket));
        }
    }

    private function notifyBreached(Ticket $ticket): void
    {
        $recipients = $this->breachRecipients($ticket);

        if ($recipients->isEmpty()) {
            // Fallback: notify any admin / super_admin so the breach is not silent
            $recipients = User::whereHas('roles', fn ($q) =>
                $q->whereIn('name', ['admin', 'super_admin'])
            )->get();
        }

        foreach ($recipients as $user) {
            $user->notify(new SlaBreachedNotification($ticket));
        }
    }

    /**
     * Recipients for the 80% warning: assigned technician + supervisor of the ticket's area.
     */
    private function warningRecipients(Ticket $ticket)
    {
        $recipients = collect();

        if ($ticket->employee_id) {
            $assigned = User::whereHas('employee', fn ($q) =>
                $q->where('id', $ticket->employee_id)
            )->first();

            if ($assigned) {
                $recipients->push($assigned);
            }
        }

        $recipients = $recipients->merge($this->areaSupervisors($ticket->area_id));

        return $recipients->unique('id');
    }

    /**
     * Recipients for a breach: supervisor of the area + all admins.
     */
    private function breachRecipients(Ticket $ticket)
    {
        $supervisors = $this->areaSupervisors($ticket->area_id);

        $admins = User::whereHas('roles', fn ($q) =>
            $q->whereIn('name', ['admin', 'super_admin'])
        )->get();

        return $supervisors->merge($admins)->unique('id');
    }

    /**
     * Find users who supervise the given area.
     * Chain: User -> employee (hasOne by email) -> managedGroups (Group.employee_id) -> area_id.
     * The User model has no direct `groups()` relationship, so we walk through Employee.
     */
    private function areaSupervisors(?int $areaId)
    {
        if (!$areaId) {
            return collect();
        }

        return User::whereHas('employee.managedGroups', fn ($q) =>
            $q->where('area_id', $areaId)
        )->get();
    }
}
