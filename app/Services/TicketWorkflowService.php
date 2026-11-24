<?php

namespace App\Services;

use App\Enums\BreachAttributionEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\RejectionReasonEnum;
use App\Enums\SlaCycleOutcomeEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TaskTypeEnum;
use App\Enums\TeamTicketEventEnum;
use App\Exceptions\TicketWorkflowException;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketHold;
use App\Models\TicketSlaCycle;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\CompanyEmail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle and SLA engine for tickets created through the type/team model
 * (agreement §4–§6, decisions 5, 6, 14–17).
 *
 * Every action checks who may perform it, moves the status, keeps the
 * current SLA cycle and assignment periods consistent, writes a
 * ticket_status_histories row and mirrors the headline SLA state onto the
 * ticket (sla_deadline / sla_breached) for the existing list screens.
 */
class TicketWorkflowService
{
    /**
     * A person who takes a ticket over with less than this share of the
     * resolution time left is not charged for its breach (decision 14).
     */
    public const HANDOVER_PROTECTION_RATIO = 0.25;

    /**
     * Share of the resolution time after which the SLA warning goes out.
     */
    public const WARNING_RATIO = 0.80;

    private const ACTIVE_STATUSES = [
        TaskStatusEnum::OPEN,
        TaskStatusEnum::ASSIGNED,
        TaskStatusEnum::IN_PROGRESS,
        TaskStatusEnum::ON_HOLD,
    ];

    public function __construct(
        private WorkingTimeCalculator $calculator,
        private GeneralSettings $settings,
        private TeamTicketNotifier $notifier,
    ) {}

    // ── Opening ─────────────────────────────────────────────────────────

    /**
     * Open a ticket on a team. The SLA clock starts now, when the ticket
     * reaches the team — not when somebody is assigned.
     *
     * @param  array<string, mixed>  $customFields  values keyed by the type's form field keys
     * @param  array<string, mixed>  $attributes  extra ticket columns (area_id, sub_area_id, title, ...)
     * @param  array<int, int|string>  $watcherEmployeeIds  people kept informed (CC)
     */
    public function open(
        User $requester,
        Team $team,
        TaskPriorityEnum $priority,
        string $description,
        array $customFields = [],
        array $attributes = [],
        array $watcherEmployeeIds = [],
    ): Ticket {
        $team->loadMissing('ticketType', 'slaPolicies', 'workCalendar');

        if (! $team->is_active || ! $team->ticketType?->is_active) {
            throw TicketWorkflowException::inactiveTeam();
        }

        $this->ensureRequiredCustomFields($team, $customFields);
        $watchers = $this->watchersFor($requester, $team, $customFields, $watcherEmployeeIds);

        $ticket = DB::transaction(function () use ($requester, $team, $priority, $description, $customFields, $attributes, $watchers): Ticket {
            $now = CarbonImmutable::now();

            $ticket = Ticket::create(array_merge($attributes, [
                'ticket_type_id' => $team->ticket_type_id,
                'team_id' => $team->id,
                'type_id' => TaskTypeEnum::OPERATION,
                'priority' => $priority,
                'status' => TaskStatusEnum::OPEN,
                'description' => $description,
                'custom_fields' => $customFields ?: null,
                'created_by' => $requester->id,
                'user_id' => $requester->id,
                'task_date' => $attributes['task_date'] ?? $now->toDateString(),
            ]));

            TicketStatusHistory::create([
                'ticket_id' => $ticket->id,
                'from_status' => null,
                'to_status' => TaskStatusEnum::OPEN->value,
                'changed_by' => $requester->id,
            ]);

            $cycle = $this->startCycle($ticket, $team, $priority, $now, 1);
            $this->mirror($ticket, $cycle);

            $ticket->watchers()->attach($watchers->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => ['added_by' => $requester->id],
            ])->all());

            return $ticket->fresh();
        });

        $this->notifier->dispatch(TeamTicketEventEnum::Opened, $ticket, $requester);

        return $ticket;
    }

    /**
     * CC people must be active and reachable by e-mail; the requester is
     * never their own CC. Types can make at least one CC mandatory.
     *
     * @param  array<string, mixed>  $customFields
     * @param  array<int, int|string>  $employeeIds
     * @return Collection<int, Employee>
     */
    private function watchersFor(User $requester, Team $team, array $customFields, array $employeeIds): Collection
    {
        $watchers = Employee::query()
            ->whereKey(array_unique(array_map('intval', $employeeIds)))
            ->when($requester->employee?->id, fn ($query, $ownId) => $query->whereKeyNot($ownId))
            ->get();

        $withoutEmail = $watchers->reject(fn (Employee $employee): bool => CompanyEmail::isCorporate($employee->email));

        if ($withoutEmail->isNotEmpty()) {
            throw TicketWorkflowException::watcherWithoutEmail($withoutEmail->pluck('name')->implode(', '));
        }

        if ($watchers->isEmpty() && $team->ticketType->requiresWatchers($customFields)) {
            throw TicketWorkflowException::watchersRequired();
        }

        return $watchers;
    }

    // ── Assignment ──────────────────────────────────────────────────────

    /**
     * Supervisor assigns (or hands over) the ticket. The SLA cycle keeps
     * running — handing over never resets the clock.
     */
    public function assign(Ticket $ticket, Employee $employee, User $actor, ?string $note = null): Ticket
    {
        $this->ensureSupervisor($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS]);
        $this->ensureCanWorkInTeam($ticket->team, $employee);

        if ((int) $ticket->employee_id === (int) $employee->id) {
            throw TicketWorkflowException::notAllowed();
        }

        return $this->handTo($ticket, $employee, $actor, selfAssigned: false, note: $note);
    }

    /**
     * Team member takes an unassigned ticket from the pool ("Üzerime Al").
     */
    public function selfAssign(Ticket $ticket, User $actor): Ticket
    {
        $this->ensureTeamTicket($ticket);
        $this->ensureStatus($ticket, [TaskStatusEnum::OPEN]);

        $team = $ticket->team;
        $employee = $actor->employee;

        if (! $team->allow_self_assign || ! $employee) {
            throw TicketWorkflowException::notAllowed();
        }

        $this->ensureCanWorkInTeam($team, $employee);

        return $this->handTo($ticket, $employee, $actor, selfAssigned: true);
    }

    // ── Work ────────────────────────────────────────────────────────────

    /**
     * Assignee starts work. The first start in a cycle stops the response
     * clock.
     */
    public function startWork(Ticket $ticket, User $actor): Ticket
    {
        $this->ensureAssignee($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::ASSIGNED]);

        return DB::transaction(function () use ($ticket, $actor): Ticket {
            $now = CarbonImmutable::now();
            $cycle = $this->cycle($ticket);

            if ($cycle->responded_at === null) {
                $cycle->responded_at = $now;
                $cycle->response_breached = $cycle->response_breached || $now->greaterThan($cycle->response_due_at);
                $cycle->save();
            }

            return $this->moveTo($ticket, TaskStatusEnum::IN_PROGRESS, $actor, null, $cycle, TeamTicketEventEnum::Started);
        });
    }

    /**
     * Pause the clock for one of the agreed reasons only.
     */
    public function hold(Ticket $ticket, User $actor, HoldReasonEnum $reason, ?string $note = null): Ticket
    {
        $this->ensureAssignee($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::IN_PROGRESS]);

        if ($reason === HoldReasonEnum::AwaitingApproval) {
            $this->ensureReason((string) $note);
        }

        return DB::transaction(function () use ($ticket, $actor, $reason, $note): Ticket {
            $now = CarbonImmutable::now();
            $cycle = $this->cycle($ticket);

            $this->evaluateResolutionBreach($cycle, $now);
            $this->pause($cycle, TicketSlaCycle::PAUSE_HOLD, $now);

            TicketHold::create([
                'ticket_id' => $ticket->id,
                'team_id' => $cycle->team_id,
                'employee_id' => $actor->employee?->id,
                'reason' => $reason,
                'note' => $note,
                'started_at' => $now,
            ]);

            $ticket->hold_reason = $reason;
            $ticket->on_hold_since = $now;

            return $this->moveTo($ticket, TaskStatusEnum::ON_HOLD, $actor, trim($reason->getLabel().' '.($note ?? '')), $cycle,
                TeamTicketEventEnum::Held, ['hold_reason' => $reason, 'note' => $note]);
        });
    }

    public function resume(Ticket $ticket, User $actor): Ticket
    {
        $this->ensureAssigneeOrSupervisor($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::ON_HOLD]);

        return DB::transaction(function () use ($ticket, $actor): Ticket {
            $now = CarbonImmutable::now();
            $cycle = $this->cycle($ticket);
            $paused = $this->unpause($cycle, $now);

            $ticket->hold_reason = null;
            $ticket->on_hold_since = null;
            $ticket->total_on_hold_minutes = (int) $ticket->total_on_hold_minutes + $paused;

            return $this->moveTo($ticket, TaskStatusEnum::IN_PROGRESS, $actor, null, $cycle);
        });
    }

    /**
     * Assignee reports the work done. The outcome is decided now (resolved
     * after the deadline = breach), then the clock pauses while the
     * requester confirms.
     */
    public function resolve(Ticket $ticket, User $actor, ?string $note = null): Ticket
    {
        $this->ensureAssignee($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::IN_PROGRESS]);

        return DB::transaction(function () use ($ticket, $actor, $note): Ticket {
            $now = CarbonImmutable::now();
            $cycle = $this->cycle($ticket);

            $this->evaluateResolutionBreach($cycle, $now);
            $cycle->resolved_at = $now;
            $this->pause($cycle, TicketSlaCycle::PAUSE_AWAITING_CONFIRMATION, $now);

            $ticket->resolved_at = $now;
            $ticket->resolution_notes = $note;
            $ticket->auto_close_at = $now->addDays($this->settings->auto_close_days);

            return $this->moveTo($ticket, TaskStatusEnum::RESOLVED, $actor, $note, $cycle, TeamTicketEventEnum::Resolved, ['note' => $note]);
        });
    }

    // ── Requester decisions ─────────────────────────────────────────────

    /**
     * Requester confirms the resolution: the cycle is sealed and the
     * ticket closes.
     */
    public function confirm(Ticket $ticket, User $actor, ?int $satisfaction = null, ?string $comment = null): Ticket
    {
        $this->ensureRequester($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::RESOLVED]);

        if ($satisfaction !== null && ($satisfaction < 1 || $satisfaction > 5)) {
            throw TicketWorkflowException::notAllowed();
        }

        // A low score must say why (agreement §6).
        if ($satisfaction !== null && $satisfaction <= 2 && blank($comment)) {
            throw TicketWorkflowException::reasonRequired();
        }

        $rating = $satisfaction === null ? [] : [
            'satisfaction_score' => $satisfaction,
            'satisfaction_comment' => filled($comment) ? $comment : null,
            'rated_at' => CarbonImmutable::now(),
        ];

        return $this->close($ticket, $actor, null, $rating);
    }

    /**
     * "Çözülmedi": the same cycle carries on. The time spent waiting for the
     * requester is not charged; the outcome is decided again at the next
     * resolution (decision 16).
     */
    public function rejectResolution(Ticket $ticket, User $actor, string $reason): Ticket
    {
        $this->ensureRequester($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::RESOLVED]);
        $this->ensureReason($reason);

        return DB::transaction(function () use ($ticket, $actor, $reason): Ticket {
            $now = CarbonImmutable::now();
            $cycle = $this->cycle($ticket);

            $this->unpause($cycle, $now);
            $cycle->resolved_at = null;
            $cycle->resolution_rejections++;
            $cycle->save();

            $ticket->resolved_at = null;
            $ticket->auto_close_at = null;

            return $this->moveTo($ticket, TaskStatusEnum::IN_PROGRESS, $actor, $reason, $cycle, TeamTicketEventEnum::ResolutionRejected, ['note' => $reason]);
        });
    }

    /**
     * Close tickets nobody confirmed within the configured number of days.
     */
    public function autoClose(Ticket $ticket): Ticket
    {
        $this->ensureTeamTicket($ticket);
        $this->ensureStatus($ticket, [TaskStatusEnum::RESOLVED]);

        if ($ticket->auto_close_at === null || $ticket->auto_close_at->isFuture()) {
            throw TicketWorkflowException::notAllowed();
        }

        return $this->close($ticket, null, __('ui.workflow_auto_closed'));
    }

    /**
     * Requester reopens a closed ticket inside the reopen window. A new SLA
     * cycle starts and the ticket goes back to whoever resolved it.
     */
    public function reopen(Ticket $ticket, User $actor, string $reason): Ticket
    {
        $this->ensureRequester($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::CLOSED]);
        $this->ensureReason($reason);

        if ($ticket->reopen_until === null || $ticket->reopen_until->isPast()) {
            throw TicketWorkflowException::reopenWindowClosed();
        }

        return DB::transaction(function () use ($ticket, $actor, $reason): Ticket {
            $now = CarbonImmutable::now();
            $previous = $this->cycle($ticket);
            $team = $previous->team;
            $resolver = $previous->assignments()->latest('started_at')->first()?->employee;

            $cycle = $this->startCycle($ticket, $team, $ticket->priority, $now, $previous->cycle_no + 1);

            $ticket->closed_at = null;
            $ticket->closed_by = null;
            $ticket->resolved_at = null;
            $ticket->reopen_until = null;
            $ticket->closure_reason = null;
            $ticket->reopened_at = $now;
            $ticket->reopened_by = $actor->id;
            $ticket->reopen_reason = mb_substr($reason, 0, 255);
            $ticket->employee_id = null;

            if ($resolver && $team->is_active && $this->canWorkInTeam($team, $resolver)) {
                $this->openAssignment($ticket, $cycle, $resolver, $actor, false, $now);
                $ticket->employee_id = $resolver->id;
                $ticket->assigned_at = $now;

                return $this->moveTo($ticket, TaskStatusEnum::ASSIGNED, $actor, $reason, $cycle, TeamTicketEventEnum::Reopened, ['note' => $reason]);
            }

            return $this->moveTo($ticket, TaskStatusEnum::OPEN, $actor, $reason, $cycle, TeamTicketEventEnum::Reopened, ['note' => $reason]);
        });
    }

    // ── Supervisor decisions ────────────────────────────────────────────

    /**
     * Requester may cancel until work starts; afterwards only the team
     * supervisor can. A cycle that had already breached keeps the flag so
     * reports can show "cancelled after breach" (decision 15).
     */
    public function cancel(Ticket $ticket, User $actor, string $reason): Ticket
    {
        $this->ensureTeamTicket($ticket);
        $this->ensureReason($reason);
        $this->ensureStatus($ticket, self::ACTIVE_STATUSES);

        $cycle = $this->cycle($ticket);
        $requesterMayCancel = $this->isRequester($ticket, $actor)
            && in_array($ticket->status, [TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED], true)
            && $cycle->responded_at === null;

        if (! $requesterMayCancel && ! $this->isSupervisor($ticket, $actor)) {
            throw TicketWorkflowException::notAllowed();
        }

        return $this->terminate($ticket, $actor, TaskStatusEnum::CANCELLED, SlaCycleOutcomeEnum::Cancelled, 'cancelled', $reason, TicketAssignment::END_CANCELLED, TeamTicketEventEnum::Cancelled);
    }

    /**
     * Supervisor rejects a request before work starts (duplicate, out of
     * scope, invalid). Never counted in performance.
     */
    public function reject(Ticket $ticket, User $actor, RejectionReasonEnum $reason, ?string $note = null): Ticket
    {
        $this->ensureSupervisor($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED]);

        return $this->terminate(
            $ticket,
            $actor,
            TaskStatusEnum::REJECTED,
            SlaCycleOutcomeEnum::Rejected,
            $reason->value,
            trim($reason->getLabel().' '.($note ?? '')),
            TicketAssignment::END_REJECTED,
            TeamTicketEventEnum::Rejected,
        );
    }

    /**
     * Move a misrouted ticket to another team. The old cycle is closed as
     * "transferred" (charged to nobody) and the new team's clock starts now.
     */
    public function transfer(Ticket $ticket, Team $target, User $actor, string $reason): Ticket
    {
        $this->ensureSupervisor($ticket, $actor);
        $this->ensureStatus($ticket, [TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS]);
        $this->ensureReason($reason);

        if (! $target->is_active || (int) $target->id === (int) $ticket->team_id) {
            throw TicketWorkflowException::inactiveTeam();
        }

        return DB::transaction(function () use ($ticket, $target, $actor, $reason): Ticket {
            $now = CarbonImmutable::now();
            $old = $this->cycle($ticket);

            // A deadline already missed stays on the old team and on whoever
            // held the ticket at the deadline; moving it must not erase it.
            $this->evaluateResolutionBreach($old, $now);
            $this->closeAssignment($ticket, TicketAssignment::END_TRANSFERRED, $now);
            $old->outcome = $old->resolution_breached ? SlaCycleOutcomeEnum::Breached : SlaCycleOutcomeEnum::Transferred;
            $old->ended_at = $now;
            $old->paused_since = null;
            $old->pause_kind = null;
            $old->save();

            $cycle = $this->startCycle($ticket, $target, $ticket->priority, $now, $old->cycle_no + 1);

            $ticket->team_id = $target->id;
            $ticket->ticket_type_id = $target->ticket_type_id;
            $ticket->employee_id = null;
            $ticket->assigned_at = null;

            return $this->moveTo($ticket, TaskStatusEnum::OPEN, $actor, $reason, $cycle,
                TeamTicketEventEnum::Transferred, ['previous_team_id' => $old->team_id, 'note' => $reason]);
        });
    }

    // ── Scheduler entry points ──────────────────────────────────────────

    /**
     * Flag a running cycle whose deadline passed. Returns true when this call
     * set the flag (so the caller notifies exactly once).
     */
    public function flagResolutionBreach(TicketSlaCycle $cycle, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if (! $cycle->isOpen() || $cycle->isPaused() || $cycle->resolution_breached) {
            return false;
        }

        $flagged = $this->evaluateResolutionBreach($cycle, $now);

        if ($flagged) {
            $cycle->save();
            $this->mirror($cycle->ticket, $cycle);
        }

        return $flagged;
    }

    public function flagResponseBreach(TicketSlaCycle $cycle, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if (! $cycle->isOpen() || $cycle->responded_at !== null || $cycle->response_breached
            || $now->lessThanOrEqualTo($cycle->response_due_at)) {
            return false;
        }

        $cycle->response_breached = true;
        $cycle->save();

        return true;
    }

    /**
     * Share of the resolution time already used (working time, pauses
     * excluded). 0.0 = just started, 1.0 = at the deadline.
     */
    public function elapsedRatio(TicketSlaCycle $cycle, ?CarbonImmutable $now = null): float
    {
        $used = $this->usedWorkingMinutes($cycle, $now);

        return $cycle->resolution_minutes > 0 ? max(0.0, $used / $cycle->resolution_minutes) : 1.0;
    }

    /**
     * Working minutes of the resolution target still left (negative once
     * overdue). Pauses are excluded and the clock stands still while paused,
     * so this is what the assignee really has — not the wall-clock gap to
     * the deadline, which includes nights and weekends.
     */
    public function remainingWorkingMinutes(TicketSlaCycle $cycle, ?CarbonImmutable $now = null): int
    {
        return $cycle->resolution_minutes - $this->usedWorkingMinutes($cycle, $now);
    }

    private function usedWorkingMinutes(TicketSlaCycle $cycle, ?CarbonImmutable $now = null): int
    {
        $until = $cycle->paused_since ?? ($now ?? CarbonImmutable::now());

        return max(0, $this->calculator->workingMinutesBetween($cycle->started_at, $until, $cycle->workCalendar)
            - $cycle->paused_minutes);
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function startCycle(Ticket $ticket, Team $team, TaskPriorityEnum $priority, CarbonImmutable $at, int $cycleNo): TicketSlaCycle
    {
        $team->loadMissing('slaPolicies', 'workCalendar');
        $policy = $team->slaPolicyFor($priority) ?? throw TicketWorkflowException::noSlaPolicy();
        $calendar = $team->workCalendar;

        return TicketSlaCycle::create([
            'ticket_id' => $ticket->id,
            'cycle_no' => $cycleNo,
            'team_id' => $team->id,
            'priority' => $priority,
            'response_minutes' => $policy->response_minutes,
            'resolution_minutes' => $policy->resolution_minutes,
            'success_threshold' => $policy->success_threshold,
            'work_calendar_id' => $calendar?->id,
            'started_at' => $at,
            'response_due_at' => $this->calculator->addWorkingMinutes($at, $policy->response_minutes, $calendar),
            'resolution_due_at' => $this->calculator->addWorkingMinutes($at, $policy->resolution_minutes, $calendar),
        ]);
    }

    private function handTo(Ticket $ticket, Employee $employee, User $actor, bool $selfAssigned, ?string $note = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $employee, $actor, $selfAssigned, $note): Ticket {
            $now = CarbonImmutable::now();
            $cycle = $this->cycle($ticket);
            $previous = $ticket->employee?->name;

            $this->closeAssignment($ticket, TicketAssignment::END_REASSIGNED, $now);
            $this->openAssignment($ticket, $cycle, $employee, $actor, $selfAssigned, $now);

            $ticket->employee_id = $employee->id;
            $ticket->assigned_at = $now;

            $line = $previous ? "{$previous} → {$employee->name}" : $employee->name;

            return $this->moveTo($ticket, TaskStatusEnum::ASSIGNED, $actor, trim($line.($note ? "\n".$note : '')), $cycle,
                TeamTicketEventEnum::Assigned, ['note' => $note]);
        });
    }

    private function openAssignment(Ticket $ticket, TicketSlaCycle $cycle, Employee $employee, ?User $actor, bool $selfAssigned, CarbonImmutable $at): TicketAssignment
    {
        return TicketAssignment::create([
            'ticket_id' => $ticket->id,
            'ticket_sla_cycle_id' => $cycle->id,
            'employee_id' => $employee->id,
            'assigned_by' => $actor?->id,
            'self_assigned' => $selfAssigned,
            'started_at' => $at,
            'remaining_ratio_at_start' => round(max(0.0, 1.0 - $this->elapsedRatio($cycle, $at)), 4),
        ]);
    }

    private function closeAssignment(Ticket $ticket, string $reason, CarbonImmutable $at): void
    {
        TicketAssignment::query()
            ->where('ticket_id', $ticket->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => $at, 'end_reason' => $reason, 'updated_at' => $at]);
    }

    /**
     * @param  array<string, mixed>  $rating  satisfaction columns for the cycle
     */
    private function close(Ticket $ticket, ?User $actor, ?string $note, array $rating = []): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor, $note, $rating): Ticket {
            $now = CarbonImmutable::now();
            $cycle = $this->cycle($ticket);
            $cycle->fill($rating);

            $cycle->paused_since = null;
            $cycle->pause_kind = null;
            $cycle->outcome = $cycle->resolution_breached ? SlaCycleOutcomeEnum::Breached : SlaCycleOutcomeEnum::OnTime;
            $cycle->ended_at = $now;
            $cycle->save();

            $this->closeAssignment($ticket, TicketAssignment::END_CLOSED, $now);

            $ticket->closed_at = $now;
            $ticket->closed_by = $actor?->id;
            $ticket->auto_close_at = null;
            $ticket->reopen_until = $now->addDays($this->settings->reopen_window_days);

            return $this->moveTo($ticket, TaskStatusEnum::CLOSED, $actor, $note, $cycle, TeamTicketEventEnum::Closed);
        });
    }

    private function terminate(
        Ticket $ticket,
        User $actor,
        TaskStatusEnum $status,
        SlaCycleOutcomeEnum $outcome,
        string $closureReason,
        string $note,
        string $assignmentEnd,
        TeamTicketEventEnum $event,
    ): Ticket {
        return DB::transaction(function () use ($ticket, $actor, $status, $outcome, $closureReason, $note, $assignmentEnd, $event): Ticket {
            $now = CarbonImmutable::now();
            $cycle = $this->cycle($ticket);

            $this->evaluateResolutionBreach($cycle, $now);

            if ($cycle->pause_kind === TicketSlaCycle::PAUSE_HOLD) {
                $this->endHold($ticket, $cycle, $now);
            }

            $cycle->paused_since = null;
            $cycle->pause_kind = null;
            $cycle->outcome = $outcome;
            $cycle->ended_at = $now;
            $cycle->save();

            $this->closeAssignment($ticket, $assignmentEnd, $now);

            $ticket->closed_at = $now;
            $ticket->closed_by = $actor->id;
            $ticket->closure_reason = $closureReason;
            $ticket->hold_reason = null;
            $ticket->on_hold_since = null;

            return $this->moveTo($ticket, $status, $actor, $note, $cycle, $event, ['note' => $note]);
        });
    }

    /**
     * Set the breach flag when the (unpaused) deadline has passed, and decide
     * who carries it based on who held the ticket at the deadline.
     */
    private function evaluateResolutionBreach(TicketSlaCycle $cycle, CarbonImmutable $now): bool
    {
        if ($cycle->resolution_breached || $cycle->isPaused() || $now->lessThanOrEqualTo($cycle->resolution_due_at)) {
            return false;
        }

        $deadline = $cycle->resolution_due_at;
        $holder = $cycle->assignments()
            ->where('started_at', '<=', $deadline)
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $deadline))
            ->latest('started_at')
            ->first();

        $cycle->resolution_breached = true;
        $cycle->breached_at = $deadline;

        if ($holder === null) {
            $cycle->breach_attribution = BreachAttributionEnum::Dispatch;
            $cycle->breach_employee_id = null;
        } elseif ($holder->remaining_ratio_at_start !== null && $holder->remaining_ratio_at_start < self::HANDOVER_PROTECTION_RATIO) {
            $cycle->breach_attribution = BreachAttributionEnum::Handover;
            $cycle->breach_employee_id = null;
        } else {
            $cycle->breach_attribution = BreachAttributionEnum::Person;
            $cycle->breach_employee_id = $holder->employee_id;
        }

        return true;
    }

    private function pause(TicketSlaCycle $cycle, string $kind, CarbonImmutable $at): void
    {
        $cycle->paused_since = $at;
        $cycle->pause_kind = $kind;
        $cycle->save();
    }

    /**
     * Resume the clock and push the deadlines back by the paused working
     * time. Returns the paused working minutes.
     */
    private function endHold(Ticket $ticket, TicketSlaCycle $cycle, CarbonImmutable $at): void
    {
        $hold = TicketHold::query()->where('ticket_id', $ticket->id)->open()->latest('id')->first();

        $hold?->update([
            'ended_at' => $at,
            'working_minutes' => $this->calculator->workingMinutesBetween($hold->started_at, $at, $cycle->workCalendar),
        ]);
    }

    /**
     * Working minutes an open hold has lasted so far.
     */
    public function holdWorkingMinutes(TicketHold $hold, ?CarbonImmutable $now = null): int
    {
        return $this->calculator->workingMinutesBetween($hold->started_at, $now ?? CarbonImmutable::now(), $hold->team?->workCalendar);
    }

    private function unpause(TicketSlaCycle $cycle, CarbonImmutable $at): int
    {
        if ($cycle->paused_since === null) {
            return 0;
        }

        $calendar = $cycle->workCalendar;
        $minutes = $this->calculator->workingMinutesBetween($cycle->paused_since, $at, $calendar);

        if ($cycle->pause_kind === TicketSlaCycle::PAUSE_HOLD) {
            $this->endHold($cycle->ticket, $cycle, $at);
        }

        if ($minutes > 0) {
            $cycle->paused_minutes += $minutes;
            $cycle->resolution_due_at = $this->calculator->addWorkingMinutes($cycle->resolution_due_at, $minutes, $calendar);

            if ($cycle->responded_at === null) {
                $cycle->response_due_at = $this->calculator->addWorkingMinutes($cycle->response_due_at, $minutes, $calendar);
            }
        }

        $cycle->paused_since = null;
        $cycle->pause_kind = null;
        $cycle->save();

        return $minutes;
    }

    /**
     * @param  array<string, mixed>  $context  passed to TeamTicketNotifier
     */
    private function moveTo(
        Ticket $ticket,
        TaskStatusEnum $to,
        ?User $actor,
        ?string $note,
        TicketSlaCycle $cycle,
        ?TeamTicketEventEnum $event = null,
        array $context = [],
    ): Ticket {
        $from = $ticket->status;
        $ticket->status = $to;
        $this->mirror($ticket, $cycle, save: false);
        $ticket->save();

        TicketStatusHistory::create([
            'ticket_id' => $ticket->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by' => $actor?->id,
            'note' => $note,
        ]);

        $fresh = $ticket->fresh();

        if ($event !== null) {
            // Only after the transaction commits: a rolled-back step must
            // never notify anyone.
            DB::afterCommit(fn () => $this->notifier->dispatch($event, $fresh, $actor, $context));
        }

        return $fresh;
    }

    /**
     * Copy the current cycle's headline SLA state onto the ticket so the
     * existing list badges, filters and tabs keep working.
     */
    private function mirror(Ticket $ticket, TicketSlaCycle $cycle, bool $save = true): void
    {
        $ticket->sla_deadline = $cycle->resolution_due_at;
        $ticket->sla_breached = $cycle->resolution_breached;

        if ($save) {
            $ticket->save();
        }
    }

    private function cycle(Ticket $ticket): TicketSlaCycle
    {
        $cycle = $ticket->currentCycle()->with('team.slaPolicies', 'workCalendar')->first();

        if ($cycle === null) {
            throw TicketWorkflowException::notAllowed();
        }

        return $cycle;
    }

    // ── Guards ──────────────────────────────────────────────────────────

    private function ensureTeamTicket(Ticket $ticket): void
    {
        if (! $ticket->usesTeamWorkflow()) {
            throw TicketWorkflowException::notAllowed();
        }
    }

    /**
     * @param  array<int, TaskStatusEnum>  $allowed
     */
    private function ensureStatus(Ticket $ticket, array $allowed): void
    {
        $this->ensureTeamTicket($ticket);

        if (! in_array($ticket->status, $allowed, true)) {
            throw TicketWorkflowException::wrongStatus($ticket->status);
        }
    }

    private function ensureReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw TicketWorkflowException::reasonRequired();
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function ensureRequiredCustomFields(Team $team, array $values): void
    {
        foreach ($team->ticketType->form_fields ?? [] as $field) {
            if (! empty($field['required']) && blank($values[$field['key']] ?? null)) {
                throw TicketWorkflowException::missingField($field['label']);
            }
        }
    }

    private function ensureSupervisor(Ticket $ticket, User $actor): void
    {
        $this->ensureTeamTicket($ticket);

        if (! $this->isSupervisor($ticket, $actor)) {
            throw TicketWorkflowException::notAllowed();
        }
    }

    private function ensureAssignee(Ticket $ticket, User $actor): void
    {
        $this->ensureTeamTicket($ticket);

        if (! $this->isAssignee($ticket, $actor) && ! $actor->hasRole('super_admin')) {
            throw TicketWorkflowException::notAllowed();
        }
    }

    private function ensureAssigneeOrSupervisor(Ticket $ticket, User $actor): void
    {
        $this->ensureTeamTicket($ticket);

        if (! $this->isAssignee($ticket, $actor) && ! $this->isSupervisor($ticket, $actor)) {
            throw TicketWorkflowException::notAllowed();
        }
    }

    private function ensureRequester(Ticket $ticket, User $actor): void
    {
        $this->ensureTeamTicket($ticket);

        if (! $this->isRequester($ticket, $actor) && ! $actor->hasRole('super_admin')) {
            throw TicketWorkflowException::notAllowed();
        }
    }

    private function ensureCanWorkInTeam(Team $team, Employee $employee): void
    {
        if (! $this->canWorkInTeam($team, $employee)) {
            throw TicketWorkflowException::notTeamMember();
        }
    }

    public function canWorkInTeam(Team $team, Employee $employee): bool
    {
        return (int) $team->supervisor_employee_id === (int) $employee->id
            || $team->members()->whereKey($employee->id)->exists();
    }

    public function isSupervisor(Ticket $ticket, User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        $employeeId = $user->employee?->id;

        return $employeeId !== null && (int) $ticket->team?->supervisor_employee_id === (int) $employeeId;
    }

    public function isAssignee(Ticket $ticket, User $user): bool
    {
        $employeeId = $user->employee?->id;

        return $employeeId !== null && (int) $ticket->employee_id === (int) $employeeId;
    }

    public function isRequester(Ticket $ticket, User $user): bool
    {
        return (int) $ticket->created_by === (int) $user->id;
    }
}
