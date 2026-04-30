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
        // Tickets eligible for SLA tracking: open lifecycle, NOT on_hold (paused), NOT cancelled.
        $openStatuses = [
            TaskStatusEnum::OPEN->value,
            TaskStatusEnum::ASSIGNED->value,
            TaskStatusEnum::IN_PROGRESS->value,
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

        // Daily de-dupe: don't repeat the same notification type for the same ticket today.
        if ($this->alreadySentToday($ticket, SlaWarningNotification::class)) {
            return;
        }

        foreach ($this->warningRecipients($ticket) as $user) {
            $user->notify(new SlaWarningNotification($ticket));
        }
    }

    private function notifyBreached(Ticket $ticket): void
    {
        if ($this->alreadySentToday($ticket, SlaBreachedNotification::class)) {
            return;
        }

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
