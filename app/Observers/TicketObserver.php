<?php

namespace App\Observers;

use App\Enums\TaskStatusEnum;
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
        // Resolve and snapshot the SLA deadline at creation time
        if ($ticket->area_id && $ticket->priority) {
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

        // Default status — open if not assigned, assigned if employee set
        if (empty($ticket->status)) {
            $ticket->status = $ticket->employee_id
                ? TaskStatusEnum::ASSIGNED
                : TaskStatusEnum::OPEN;
        }

        // If created already-assigned, stamp assigned_at
        if ($ticket->status === TaskStatusEnum::ASSIGNED && empty($ticket->assigned_at)) {
            $ticket->assigned_at = now();
        }
    }

    public function created(Ticket $ticket): void
    {
        // Initial history row
        TicketStatusHistory::create([
            'ticket_id'   => $ticket->id,
            'from_status' => null,
            'to_status'   => $ticket->status?->value,
            'changed_by'  => auth()->id(),
            'note'        => 'Ticket created',
        ]);

        // Notify assignee if created already-assigned (no `updated` event in this flow)
        if ($ticket->employee_id) {
            $this->notifyAssignedUser($ticket);
        }
    }

    public function updated(Ticket $ticket): void
    {
        // Notify on direct employee_id reassignment (admin reassigns via Edit page).
        // TicketService::transition() handles status-change notifications via the
        // TicketStatusChanged event, so we only handle pure reassignments here.
        if ($ticket->wasChanged('employee_id')
            && $ticket->employee_id
            && !$ticket->wasChanged('status')) {
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
}
