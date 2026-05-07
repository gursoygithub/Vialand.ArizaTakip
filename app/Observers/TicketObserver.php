<?php

namespace App\Observers;

use App\Enums\TaskStatusEnum;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Services\SlaService;

class TicketObserver
{
    /**
     * Toggle to skip the assignment notification fired from updated().
     * TicketService::reassign sets this around its $ticket->update(...) so
     * the observer does not double-fire alongside the service's own
     * notifyAssignee / transition path.
     */
    public static bool $skipReassignNotification = false;

    public function __construct(private SlaService $slaService) {}

    /**
     * Runs on every save (create + update). Whenever an employee is attached
     * to a ticket and the ticket has no assigned_at yet, stamp it now.
     * Covers the case where the Ata action does $ticket->update(['employee_id'])
     * without going through TicketService::transition() — the match-arm in
     * transition() only fires on → ASSIGNED, so a ticket that jumps OPEN → IN_PROGRESS
     * directly (allowed by the transition matrix) would otherwise never record
     * an assigned_at.
     */
    public function saving(Ticket $ticket): void
    {
        if ($ticket->employee_id && empty($ticket->assigned_at)) {
            $ticket->assigned_at = now();
        }

        // SLA deadline recalculation on priority change. Only applies to
        // existing non-terminal tickets — creating() owns the initial
        // snapshot. Rebases from now() + the resolved policy's
        // deadline_minutes and re-adds total_on_hold_minutes so a ticket
        // that has been on hold keeps the extension it earned. Runs BEFORE
        // the breach flip below so the flip evaluates the freshly-calculated
        // deadline within the same save. We still don't assign sla_breached
        // here — the flip is the canonical writer.
        $priorityRecalcTerminal = [
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::CANCELLED,
        ];

        if ($ticket->exists
            && $ticket->isDirty('priority')
            && $ticket->area_id
            && $ticket->priority
            && !in_array($ticket->status, $priorityRecalcTerminal, true)) {
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
                $ticket->sla_deadline = now()
                    ->addMinutes($policy->deadline_minutes)
                    ->addMinutes((int) $ticket->total_on_hold_minutes);
            }
        }

        // Keep the indexed sla_breached column in sync with the live deadline
        // on every save. This is what makes filters like the "breached" tab
        // and the navigation badge fast and accurate without depending on
        // CheckSlaBreaches running. Terminal statuses are excluded from the
        // flip path because their breach state is set deterministically by
        // TicketService::transition (markClosed / markCancelled / RESOLVED).
        $terminalStatuses = [
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::COMPLETED,
            TaskStatusEnum::CANCELLED,
        ];

        $isTerminal = in_array($ticket->status, $terminalStatuses, true);

        if ($ticket->sla_deadline && !$isTerminal && now()->gt($ticket->sla_deadline)) {
            $ticket->sla_breached = true;
        }

        if ($isTerminal && $ticket->sla_deadline && now()->lte($ticket->sla_deadline)) {
            $ticket->sla_breached = false;
        }
    }

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
            && !$ticket->wasChanged('status')
            && !static::$skipReassignNotification) {
            $this->notifyAssignedUser($ticket);
        }
    }

    private function notifyAssignedUser(Ticket $ticket): void
    {
        $assignedUser = User::whereHas('employee', fn ($q) =>
            $q->where('id', $ticket->employee_id)
        )->first();

        if (!$assignedUser) {
            return;
        }

        // Per-ticket mute. TicketService::reassign() clears the mute for a
        // new assignee before update() runs, so reaching this branch means
        // the user actively muted the ticket without an intervening
        // reassignment to themselves — respect their choice.
        if ($ticket->isMutedBy($assignedUser)) {
            return;
        }

        $assignedUser->notify(new TicketAssignedNotification($ticket));

        // Desktop push — same actor-name-prefixed body as the
        // TicketService::pushAssignmentFcm path. The observer fires for
        // creation-with-assignee and direct employee_id edits, which don't
        // route through TicketService.
        if ($assignedUser->wantsNotification('ticket_assigned', 'database')) {
            $actorName = auth()->user()?->employee?->name
                      ?? auth()->user()?->name
                      ?? 'Sistem';
            $area     = $ticket->area?->name ?? '';
            $priority = $ticket->priority?->getLabel() ?? '';

            app(\App\Services\FcmService::class)->sendToUser(
                $assignedUser,
                $ticket->ticket_no . ' • Size Atandı',
                $actorName . ' tarafından atandı — ' . $area . ' / ' . $priority,
                url('/tickets/' . $ticket->id),
            );
        }
    }
}
