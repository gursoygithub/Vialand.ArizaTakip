<?php

namespace App\Services;

use App\Enums\TaskStatusEnum;
use App\Events\TicketStatusChanged;
use App\Exceptions\TicketTransitionException;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCancelledNotification;
use App\Notifications\TicketClosedNotification;
use App\Notifications\TicketCommentNotification;
use App\Notifications\TicketReopenedNotification;
use Illuminate\Support\Facades\DB;

class TicketService
{
    /**
     * Allowed transitions: from → [to, …].
     * The legacy PENDING/COMPLETED/WINTER_MAINTENANCE statuses are mapped
     * onto the new lifecycle (PENDING ≈ open, COMPLETED ≈ closed).
     */
    private const TRANSITIONS = [
        // Reform lifecycle
        TaskStatusEnum::OPEN->value        => [TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::CANCELLED],
        TaskStatusEnum::ASSIGNED->value    => [TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::ON_HOLD, TaskStatusEnum::CANCELLED],
        TaskStatusEnum::IN_PROGRESS->value => [TaskStatusEnum::RESOLVED, TaskStatusEnum::ON_HOLD, TaskStatusEnum::CANCELLED],
        TaskStatusEnum::ON_HOLD->value     => [TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::CANCELLED],
        TaskStatusEnum::RESOLVED->value    => [TaskStatusEnum::CLOSED, TaskStatusEnum::IN_PROGRESS],
        TaskStatusEnum::CLOSED->value      => [TaskStatusEnum::IN_PROGRESS], // reopen — permission gated separately
        TaskStatusEnum::CANCELLED->value   => [], // terminal

        // Legacy
        TaskStatusEnum::PENDING->value     => [TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::COMPLETED, TaskStatusEnum::CANCELLED],
        TaskStatusEnum::COMPLETED->value   => [TaskStatusEnum::IN_PROGRESS],
    ];

    public function __construct(private SlaService $slaService) {}

    /**
     * Transition a ticket to a new status. Validates against the matrix,
     * sets lifecycle timestamps, pauses/resumes the SLA clock for on_hold,
     * writes a ticket_status_histories row, and dispatches TicketStatusChanged.
     *
     * @throws TicketTransitionException when the transition is not allowed.
     */
    public function transition(
        Ticket $ticket,
        TaskStatusEnum $toStatus,
        User $by,
        ?string $note = null,
    ): Ticket {
        $from = $ticket->status;

        if (!$this->isAllowed($from, $toStatus)) {
            throw TicketTransitionException::invalid($from, $toStatus);
        }

        return DB::transaction(function () use ($ticket, $from, $toStatus, $by, $note) {
            // Resume SLA clock if leaving on_hold
            if ($from === TaskStatusEnum::ON_HOLD && $toStatus !== TaskStatusEnum::ON_HOLD) {
                $this->slaService->extendDeadlineForOnHold($ticket);
            }

            // Apply transition timestamps
            match ($toStatus) {
                TaskStatusEnum::ASSIGNED    => $ticket->assigned_at  = $ticket->assigned_at  ?? now(),
                TaskStatusEnum::IN_PROGRESS => null, // no dedicated timestamp; reopens cleared on close
                TaskStatusEnum::RESOLVED    => $ticket->resolved_at  = now(),
                TaskStatusEnum::ON_HOLD     => $ticket->on_hold_since = now(),
                TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED => $this->markClosed($ticket, $by),
                TaskStatusEnum::CANCELLED   => $this->markCancelled($ticket, $by),
                default                     => null,
            };

            // Reopen path — clear closed_at so future closes record new timestamp
            if (in_array($from, [TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED, TaskStatusEnum::RESOLVED], true)
                && $toStatus === TaskStatusEnum::IN_PROGRESS) {
                $ticket->closed_at    = null;
                $ticket->closed_by    = null;
                $ticket->resolved_at  = null;
                $ticket->sla_breached = $ticket->sla_deadline ? now()->isAfter($ticket->sla_deadline) : false;
            }

            $ticket->status = $toStatus;
            $ticket->save();

            TicketStatusHistory::create([
                'ticket_id'   => $ticket->id,
                'from_status' => $from?->value,
                'to_status'   => $toStatus->value,
                'changed_by'  => $by->id,
                'note'        => $note,
            ]);

            $fresh = $ticket->fresh();

            $this->dispatchTransitionNotifications($fresh, $from, $toStatus);

            event(new TicketStatusChanged($fresh, $from, $toStatus, $by, $note));

            return $fresh;
        });
    }

    /**
     * Fire the right notification(s) for a given status transition.
     */
    private function dispatchTransitionNotifications(Ticket $ticket, ?TaskStatusEnum $from, TaskStatusEnum $to): void
    {
        // → ASSIGNED: notify the technician
        if ($to === TaskStatusEnum::ASSIGNED && $ticket->employee_id) {
            $assignee = $this->userForEmployee($ticket->employee_id);
            $assignee?->notify(new TicketAssignedNotification($ticket));
        }

        // → CLOSED / COMPLETED: notify the creator
        if (in_array($to, [TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED], true)) {
            $creator = User::find($ticket->created_by);
            $creator?->notify(new TicketClosedNotification($ticket));
        }

        // → CANCELLED: notify the creator
        if ($to === TaskStatusEnum::CANCELLED) {
            $creator = User::find($ticket->created_by);
            $creator?->notify(new TicketCancelledNotification($ticket));
        }

        // Reopen path: was closed/resolved/completed → in_progress
        if ($to === TaskStatusEnum::IN_PROGRESS
            && in_array($from, [TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED, TaskStatusEnum::RESOLVED], true)) {
            // Notify previous assignee + supervisor of the area
            $recipients = collect();
            if ($ticket->employee_id) {
                $assignee = $this->userForEmployee($ticket->employee_id);
                if ($assignee) {
                    $recipients->push($assignee);
                }
            }

            if ($ticket->area_id) {
                $supervisors = User::whereHas('employee.managedGroups', fn ($q) =>
                    $q->where('area_id', $ticket->area_id)
                )->get();
                $recipients = $recipients->merge($supervisors);
            }

            foreach ($recipients->unique('id') as $user) {
                $user->notify(new TicketReopenedNotification($ticket));
            }
        }
    }

    private function userForEmployee(int $employeeId): ?User
    {
        return User::whereHas('employee', fn ($q) => $q->where('id', $employeeId))->first();
    }

    /**
     * Assign or reassign a ticket to an employee. Always writes a history row
     * so the timeline records the change. If the ticket was OPEN we delegate
     * to transition() so the entry shows up as OPEN → ASSIGNED with its
     * own notification + event; otherwise we log a from=to=current entry
     * (the timeline renders this as a non-status update) and notify the new
     * assignee directly. assigned_at is stamped by TicketObserver::saving()
     * when employee_id transitions from empty.
     *
     * @param Ticket $ticket
     * @param int    $employeeId  target Employee::id
     * @param User   $by          actor (for changed_by + notification dedupe)
     * @param ?string $note       optional extra note appended to the auto-generated
     *                            "Old → New" reassignment line
     */
    public function reassign(Ticket $ticket, int $employeeId, User $by, ?string $note = null): Ticket
    {
        $newEmployee = Employee::find($employeeId);
        if (!$newEmployee) {
            throw new \InvalidArgumentException("Employee {$employeeId} not found");
        }

        $oldEmployeeName = $ticket->employee?->name;
        $statusBefore    = $ticket->status;

        $reassignLine = $oldEmployeeName
            ? "{$oldEmployeeName} → {$newEmployee->name}"
            : "Atandı: {$newEmployee->name}";

        $fullNote = trim($reassignLine . ($note ? "\n" . $note : ''));

        return DB::transaction(function () use ($ticket, $employeeId, $by, $fullNote, $statusBefore) {
            $ticket->update(['employee_id' => $employeeId]);

            // OPEN → ASSIGNED: real status transition + history + notification
            // all handled inside transition().
            if ($statusBefore === TaskStatusEnum::OPEN) {
                try {
                    $this->transition($ticket->fresh(), TaskStatusEnum::ASSIGNED, $by, $fullNote);
                } catch (TicketTransitionException) {
                    // raced past OPEN — fall through to the from=to log path.
                    $this->logReassign($ticket->fresh(), $by, $fullNote);
                    $this->notifyAssignee($ticket->fresh(), $by);
                }
                return $ticket->fresh();
            }

            // Status didn't change — log the reassignment as a from=to entry
            // so the timeline still shows it (rendered same as a comment).
            $this->logReassign($ticket->fresh(), $by, $fullNote);
            $this->notifyAssignee($ticket->fresh(), $by);

            return $ticket->fresh();
        });
    }

    private function logReassign(Ticket $ticket, User $by, string $note): void
    {
        $current = $ticket->status?->value;
        TicketStatusHistory::create([
            'ticket_id'   => $ticket->id,
            'from_status' => $current,
            'to_status'   => $current,
            'changed_by'  => $by->id,
            'note'        => $note,
        ]);
    }

    private function notifyAssignee(Ticket $ticket, User $by): void
    {
        if (!$ticket->employee_id) {
            return;
        }
        $assignee = $this->userForEmployee($ticket->employee_id);
        if ($assignee && $assignee->id !== $by->id) {
            $assignee->notify(new TicketAssignedNotification($ticket));
        }
    }

    /**
     * Add a comment without changing status.
     * Stored in ticket_status_histories with from_status = to_status = current.
     */
    public function addComment(Ticket $ticket, User $by, string $note): TicketStatusHistory
    {
        $current = $ticket->status?->value;

        $history = TicketStatusHistory::create([
            'ticket_id'   => $ticket->id,
            'from_status' => $current,
            'to_status'   => $current,
            'changed_by'  => $by->id,
            'note'        => $note,
        ]);

        // Notify the assigned employee, but never notify the commenter themselves.
        if ($ticket->employee_id) {
            $assignee = $this->userForEmployee($ticket->employee_id);
            if ($assignee && $assignee->id !== $by->id) {
                $assignee->notify(new TicketCommentNotification($ticket, $by, $note));
            }
        }

        return $history;
    }

    /**
     * Whether a comment may be edited by the given user.
     * Authors may edit their own comments within 10 minutes of posting.
     */
    public function canEditComment(TicketStatusHistory $entry, User $user): bool
    {
        if ($entry->changed_by !== $user->id) {
            return false;
        }

        if ($entry->from_status?->value !== $entry->to_status?->value) {
            return false; // it's a status change, not a pure comment
        }

        return $entry->created_at?->diffInMinutes(now()) < 10;
    }

    public function isAllowed(?TaskStatusEnum $from, TaskStatusEnum $to): bool
    {
        if ($from === null) {
            return $to === TaskStatusEnum::OPEN || $to === TaskStatusEnum::ASSIGNED;
        }

        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * Allowed next statuses from the current one (for action-button rendering).
     *
     * @return list<TaskStatusEnum>
     */
    public function allowedNextStatuses(?TaskStatusEnum $from): array
    {
        if ($from === null) {
            return [];
        }
        return self::TRANSITIONS[$from->value] ?? [];
    }

    private function markClosed(Ticket $ticket, User $by): void
    {
        $ticket->closed_at    = now();
        $ticket->closed_by    = $by->id;
        $ticket->sla_breached = $ticket->sla_deadline && now()->isAfter($ticket->sla_deadline);
    }

    private function markCancelled(Ticket $ticket, User $by): void
    {
        $ticket->closed_at    = $ticket->closed_at ?? now();
        $ticket->closed_by    = $ticket->closed_by ?? $by->id;
        // Cancelled tickets are excluded from SLA — clear breach flag
        $ticket->sla_breached = false;
    }
}
