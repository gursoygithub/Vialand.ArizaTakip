<?php

namespace App\Observers;

use App\Enums\TaskStatusEnum;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketClosedNotification;
use App\Services\SlaService;

class TicketObserver
{
    public function __construct(private SlaService $slaService) {}

    public function creating(Ticket $ticket): void
    {
        if ($ticket->area_id && $ticket->unit_id && $ticket->priority) {
            $priorityValue = is_object($ticket->priority)
                ? $ticket->priority->value
                : $ticket->priority;

            $policy = $this->slaService->resolvePolicy(
                $ticket->area_id,
                $ticket->sub_area_id,
                $ticket->unit_id,
                $priorityValue
            );

            if ($policy) {
                $ticket->sla_deadline = $this->slaService->calculateDeadline($policy, now());
            }
        }
    }

    public function created(Ticket $ticket): void
    {
        // Log initial status in history
        TicketStatusHistory::create([
            'ticket_id'   => $ticket->id,
            'from_status' => null,
            'to_status'   => $ticket->status?->value,
            'changed_by'  => auth()->id(),
            'note'        => 'Ticket created',
        ]);

        // If ticket was created already-assigned (form submitted with employee_id),
        // there is no `updated` event to fire the assignment notification — do it here.
        if ($ticket->employee_id) {
            $this->notifyAssignedUser($ticket);
        }
    }

    private function notifyAssignedUser(Ticket $ticket): void
    {
        $assignedUser = User::whereHas('employee', fn ($q) =>
            $q->where('id', $ticket->employee_id)
        )->first();

        if ($assignedUser) {
            $assignedUser->notify(new TicketAssignedNotification($ticket));
        }
    }

    public function updating(Ticket $ticket): void
    {
        if (!$ticket->isDirty('status')) {
            return;
        }

        $newStatus = $ticket->status;

        if ($newStatus === TaskStatusEnum::ASSIGNED && !$ticket->assigned_at) {
            $ticket->assigned_at = now();
        }

        if ($newStatus === TaskStatusEnum::RESOLVED && !$ticket->resolved_at) {
            $ticket->resolved_at = now();
        }

        if (in_array($newStatus, [TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED])) {
            if (!$ticket->closed_at) {
                $ticket->closed_at = now();
                $ticket->closed_by = auth()->id() ?? $ticket->closed_by;
            }

            $ticket->sla_breached = $ticket->sla_deadline
                ? now()->isAfter($ticket->sla_deadline)
                : false;
        }
    }

    public function updated(Ticket $ticket): void
    {
        if ($ticket->wasChanged('status')) {
            TicketStatusHistory::create([
                'ticket_id'   => $ticket->id,
                'from_status' => $ticket->getOriginal('status'),
                'to_status'   => $ticket->status?->value,
                'changed_by'  => auth()->id(),
                'note'        => null,
            ]);

            $newStatus = $ticket->status;

            // Notify assigned technician when ticket is assigned
            if ($newStatus === TaskStatusEnum::ASSIGNED && $ticket->employee_id) {
                $this->notifyAssignedUser($ticket);
            }

            // Notify ticket creator when ticket is closed
            if (in_array($newStatus, [TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED])) {
                $creator = User::find($ticket->created_by);
                if ($creator) {
                    $creator->notify(new TicketClosedNotification($ticket));
                }
            }
        }

        // Notify if employee changed (re-assignment) — but skip if status also went
        // to ASSIGNED in the same update, the branch above already handled it.
        if ($ticket->wasChanged('employee_id')
            && $ticket->employee_id
            && $ticket->status !== TaskStatusEnum::ASSIGNED) {
            $this->notifyAssignedUser($ticket);
        }
    }
}
