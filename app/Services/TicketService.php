<?php

namespace App\Services;

use App\Enums\TaskStatusEnum;
use App\Events\TicketStatusChanged;
use App\Exceptions\TicketTransitionException;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Ticket;
use App\Models\TicketMute;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCancelledNotification;
use App\Notifications\TicketCommentNotification;
use App\Notifications\TicketReassignedNotification;
use App\Notifications\TicketReopenedNotification;
use App\Notifications\TicketResolvedNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Observers\TicketObserver;
use App\Enums\TaskPriorityEnum;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
        TaskStatusEnum::RESOLVED->value    => [TaskStatusEnum::CLOSED, TaskStatusEnum::ASSIGNED],
        TaskStatusEnum::CLOSED->value      => [TaskStatusEnum::ASSIGNED], // reopen — permission gated separately
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
                TaskStatusEnum::RESOLVED    => $this->markResolved($ticket),
                TaskStatusEnum::ON_HOLD     => $ticket->on_hold_since = now(),
                TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED => $this->markClosed($ticket, $by),
                TaskStatusEnum::CANCELLED   => $this->markCancelled($ticket, $by),
                default                     => null,
            };

            // Reopen path — clear closed_at so future closes record new
            // timestamp, and rebase the SLA window from now() so the
            // reopened ticket gets a fresh deadline. CANCELLED is included
            // in the source set per the reopen-recalc rule even though the
            // current transition matrix doesn't expose CANCELLED →
            // ASSIGNED — the reset is in place if/when that path opens.
            if (in_array($from, [TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED, TaskStatusEnum::RESOLVED, TaskStatusEnum::CANCELLED], true)
                && $toStatus === TaskStatusEnum::ASSIGNED) {
                $ticket->closed_at    = null;
                $ticket->closed_by    = null;
                $ticket->resolved_at  = null;
                // Reset assigned_at so the lifecycle strip's "İşleme Alındı"
                // filter (created_at >= assigned_at) scopes to the new
                // assignment cycle. TicketObserver::saving re-stamps it to
                // now() because employee_id is preserved across the reopen.
                $ticket->assigned_at  = null;

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
                        $ticket->sla_deadline = now()
                            ->addMinutes($policy->deadline_minutes)
                            ->addMinutes((int) $ticket->total_on_hold_minutes);
                    }
                }

                // Reset unconditionally per spec — the breach flip in
                // TicketObserver::saving may flip this back to true on the
                // immediate save if no policy was found and the old
                // deadline is in the past, which is correct: the ticket
                // really is still in breach against its un-rebased deadline.
                $ticket->sla_breached = false;
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

            $this->dispatchTransitionNotifications($fresh, $from, $toStatus, $by, $note);

            event(new TicketStatusChanged($fresh, $from, $toStatus, $by, $note));

            return $fresh;
        });
    }

    /**
     * Fire the right notification(s) for a given status transition.
     *
     * Special-cased transitions (each sends to a narrower recipient set):
     *   OPEN → ASSIGNED   → assignee only (TicketAssignedNotification)
     *   terminal → ASSIGNED → assignee + creator (TicketReopenedNotification)
     *   → RESOLVED         → creator only (TicketResolvedNotification)
     *   → CANCELLED        → current assignee only (TicketCancelledNotification)
     *
     * All other transitions fan out TicketStatusChangedNotification (database
     * only) to the full participant set via notifyParticipants.
     */
    private function dispatchTransitionNotifications(Ticket $ticket, ?TaskStatusEnum $from, TaskStatusEnum $to, User $actor, ?string $note = null): void
    {
        // RULE 1: Initial assignment (OPEN → ASSIGNED)
        if ($from === TaskStatusEnum::OPEN
            && $to === TaskStatusEnum::ASSIGNED
            && $ticket->employee_id) {
            $assignee = $this->userForEmployee($ticket->employee_id);
            if ($assignee && $assignee->id !== $actor->id) {
                $assignee->notify(new TicketAssignedNotification($ticket));
                $this->pushAssignmentFcm($ticket, $assignee, $actor);
            }
            return;
        }

        // RULE 5: Reopen (terminal → ASSIGNED)
        $terminalStatuses = [
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::COMPLETED,
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CANCELLED,
        ];
        if ($to === TaskStatusEnum::ASSIGNED && in_array($from, $terminalStatuses, true)) {
            $this->notifyReopened($ticket, $actor);
            return;
        }

        // RULE 3: Resolved → creator only
        if ($to === TaskStatusEnum::RESOLVED) {
            $this->notifyResolved($ticket, $actor);
            return;
        }

        // RULE 4: Cancelled → current assignee only
        if ($to === TaskStatusEnum::CANCELLED) {
            $this->notifyCancelled($ticket, $actor);
            return;
        }

        // ON_HOLD: participants (standard) + group supervisor extra mail/bell
        if ($to === TaskStatusEnum::ON_HOLD) {
            $fromLabel = $from?->getLabel() ?? '—';
            $actorName = $this->actorDisplayName($actor);

            $this->notifyParticipants(
                $ticket,
                $actor,
                new TicketStatusChangedNotification($ticket, $from, $to, $actor),
                $ticket->ticket_no . ' • Beklemede',
                "{$actorName}: {$fromLabel} → Beklemede",
            );

            // Additionally notify the group supervisor if they are not already
            // a participant (creator / current assignee / history actor).
            if ($ticket->group_id) {
                $supervisor = Group::find($ticket->group_id)?->manager?->user;
                if ($supervisor
                    && $supervisor->id !== $actor->id
                    && !$this->isParticipant($ticket, $supervisor)) {
                    $supervisor->notify(
                        new TicketStatusChangedNotification($ticket, $from, $to, $actor)
                    );
                    Mail::to($supervisor->email)
                        ->queue(new \App\Mail\TicketOnHoldMail($ticket, $actor, $note));
                    app(FcmService::class)->sendToUser(
                        $supervisor,
                        $ticket->ticket_no . ' • Beklemede',
                        "{$actorName}: {$fromLabel} → Beklemede",
                        url('/tickets/' . $ticket->id),
                    );
                }
            }
            return;
        }

        // All other transitions: database-only bell to full participant set
        $fromLabel = $from?->getLabel() ?? '—';
        $toLabel   = $to->getLabel();
        $actorName = $this->actorDisplayName($actor);

        $this->notifyParticipants(
            $ticket,
            $actor,
            new TicketStatusChangedNotification($ticket, $from, $to, $actor),
            $ticket->ticket_no . ' • Durum Değişti',
            "{$actorName}: {$fromLabel} → {$toLabel}",
        );
    }

    /**
     * RULE 3: Resolved — notify creator (mail + panel + FCM) and group
     * supervisor (mail + panel) when the supervisor is not already the
     * creator or the actor.
     */
    private function notifyResolved(Ticket $ticket, User $actor): void
    {
        if (!$ticket->created_by) {
            return;
        }
        $creator = User::find($ticket->created_by);
        if (!$creator || $creator->id === $actor->id) {
            return;
        }
        if ($ticket->isMutedBy($creator)) {
            return;
        }

        $creator->notify(new TicketResolvedNotification($ticket));

        app(FcmService::class)->sendToUser(
            $creator,
            $ticket->ticket_no . ' • Talep Çözüldü',
            $this->actorDisplayName($actor) . ' talebi çözdü',
            url('/tickets/' . $ticket->id),
        );

        // Also notify the group supervisor, unless they are already the
        // creator (already notified above) or they are the actor.
        if ($ticket->group_id) {
            $supervisor = Group::find($ticket->group_id)?->manager?->user;
            if ($supervisor
                && $supervisor->id !== $actor->id
                && $supervisor->id !== (int) $ticket->created_by) {
                $supervisor->notify(new TicketResolvedNotification($ticket));
            }
        }
    }

    /**
     * RULE 4: Cancelled — notify current assignee only (mail + panel + FCM).
     * Skipped if there is no assignee or the assignee is the actor.
     */
    private function notifyCancelled(Ticket $ticket, User $actor): void
    {
        if (!$ticket->employee_id) {
            return;
        }
        $assignee = $this->userForEmployee($ticket->employee_id);
        if (!$assignee || $assignee->id === $actor->id) {
            return;
        }
        if ($ticket->isMutedBy($assignee)) {
            return;
        }

        $assignee->notify(new TicketCancelledNotification($ticket));

        app(FcmService::class)->sendToUser(
            $assignee,
            $ticket->ticket_no . ' • Talep İptal Edildi',
            $this->actorDisplayName($actor) . ' talebi iptal etti',
            url('/tickets/' . $ticket->id),
        );
    }

    /**
     * RULE 5: Reopened — notify assignee (if any) + creator (mail + panel + FCM).
     * Each recipient gets one notification; actor and muted users are excluded.
     */
    private function notifyReopened(Ticket $ticket, User $actor): void
    {
        $recipients = collect();

        if ($ticket->employee_id) {
            $assignee = $this->userForEmployee($ticket->employee_id);
            if ($assignee && $assignee->id !== $actor->id && !$ticket->isMutedBy($assignee)) {
                $recipients->push($assignee);
            }
        }

        if ($ticket->created_by) {
            $creator = User::find($ticket->created_by);
            if ($creator
                && $creator->id !== $actor->id
                && !$recipients->contains('id', $creator->id)
                && !$ticket->isMutedBy($creator)) {
                $recipients->push($creator);
            }
        }

        $actorName = $this->actorDisplayName($actor);

        foreach ($recipients as $user) {
            $user->notify(new TicketReopenedNotification($ticket));

            app(FcmService::class)->sendToUser(
                $user,
                $ticket->ticket_no . ' • Talep Yeniden Açıldı',
                $actorName . ' talebi yeniden açtı',
                url('/tickets/' . $ticket->id),
            );
        }
    }

    /**
     * Resolve the participant set for a ticket activity:
     * - everyone who has touched the ticket (TicketStatusHistory.changed_by),
     * - plus the current creator and current assignee's User,
     * - minus the actor.
     *
     * Used for activity notifications (comments, status changes). SLA
     * notifications use a narrower set — see CheckSlaBreaches::slaRecipients.
     */
    private function getTicketParticipants(Ticket $ticket, User $actor): \Illuminate\Support\Collection
    {
        $participantIds = TicketStatusHistory::where('ticket_id', $ticket->id)
            ->whereNotNull('changed_by')
            ->distinct()
            ->pluck('changed_by');

        $additionalIds = collect([
            $ticket->created_by,
            $ticket->employee?->user?->id,
        ])->filter();

        $actorId = (int) $actor->id;

        $allIds = $participantIds->merge($additionalIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $id === $actorId);

        if ($allIds->isEmpty()) {
            return collect();
        }

        // Per-ticket mutes: a user can opt out of all activity notifications
        // on a single ticket via the toggleMute header action. Strip them
        // from the participant set here so both DB and FCM paths are
        // silenced without each call site needing its own check.
        $mutedUserIds = TicketMute::where('ticket_id', $ticket->id)
            ->pluck('user_id');

        // Belt-and-suspenders: also exclude the actor at the SQL layer in
        // case the collection-side reject misses a stale id from a model
        // override or a denormalized cache.
        return User::whereIn('id', $allIds)
            ->whereNotIn('id', $mutedUserIds)
            ->where('id', '!=', $actorId)
            ->get();
    }

    /**
     * Check whether a user is a participant on the ticket: creator, current
     * assignee, or anyone who has touched it via ticket_status_histories.
     * Used to avoid double-notifying the group supervisor when they are
     * already in the regular participant set.
     */
    private function isParticipant(Ticket $ticket, User $user): bool
    {
        if ((int) $ticket->created_by === (int) $user->id) {
            return true;
        }

        if ($ticket->employee_id
            && (int) ($ticket->employee?->user?->id ?? 0) === (int) $user->id) {
            return true;
        }

        return TicketStatusHistory::where('ticket_id', $ticket->id)
            ->where('changed_by', $user->id)
            ->exists();
    }

    /**
     * Send $notification to every participant on $ticket (minus the actor).
     * Each notification is cloned so a queued ShouldQueue's per-instance
     * state can't bleed across recipients. The notification's own via()
     * still consults the user's per-type preference, so this layer is the
     * "who" and via() is the "how".
     *
     * If $fcmTitle and $fcmBody are non-empty, also dispatches a Firebase
     * Cloud Messaging push to the same set of recipients in one go. FCM is
     * a no-op when credentials aren't configured, so passing the strings
     * unconditionally is safe.
     */
    private function notifyParticipants(
        Ticket $ticket,
        User $actor,
        BaseNotification $notification,
        string $fcmTitle = '',
        string $fcmBody = '',
    ): void {
        // Participants already excludes the actor (see getTicketParticipants).
        $participants = $this->getTicketParticipants($ticket, $actor);

        // Database side: notification's own via() consults the user's
        // per-type preference, so each user can opt out independently.
        $participants->each(fn (User $user) => $user->notify(clone $notification));

        if ($fcmTitle === '' || $fcmBody === '') {
            return;
        }

        // FCM side: filter the same participants by their database preference
        // for this notification type. If the user has turned off the in-app
        // bell for this type, they shouldn't get a desktop push for it
        // either. The actor-exclusion is enforced once here AND defended
        // again inside FcmService::sendToUsers via $excludeUserId.
        $type = method_exists($notification, 'getNotificationType')
            ? $notification->getNotificationType()
            : null;

        $fcmRecipients = $type
            ? $participants->filter(fn (User $u) => $u->wantsNotification($type, 'database'))
            : $participants;

        app(\App\Services\FcmService::class)->sendToUsers(
            $fcmRecipients,
            $fcmTitle,
            $fcmBody,
            url('/tickets/' . $ticket->id),
            $actor->id,
        );
    }

    private function userForEmployee(int $employeeId): ?User
    {
        return User::whereHas('employee', fn ($q) => $q->where('id', $employeeId))->first();
    }

    /**
     * Display name for FCM body lines. Prefers the human-readable
     * employee.name (synced from LDAP) over user.name (often a username).
     */
    private function actorDisplayName(User $actor): string
    {
        return $actor->employee?->name ?? $actor->name ?? '—';
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

        $oldEmployeeName = $ticket->employee?->name ?? '—';
        $newEmployeeName = $newEmployee->name;
        $statusBefore    = $ticket->status;

        $reassignLine = $ticket->employee?->name
            ? "{$oldEmployeeName} → {$newEmployeeName}"
            : "Atandı: {$newEmployeeName}";

        $fullNote    = trim($reassignLine . ($note ? "\n" . $note : ''));
        $oldEmployee = $ticket->employee;

        return DB::transaction(function () use ($ticket, $employeeId, $newEmployee, $by, $fullNote, $statusBefore, $oldEmployeeName, $newEmployeeName, $oldEmployee) {
            // Auto-unmute the new assignee BEFORE the update — TicketObserver's
            // updated() hook fires inside $ticket->update() and consults the
            // mute table. If the new assignee had previously muted this
            // ticket, leaving the row in place would silence the assignment
            // notification they need most.
            $newAssigneeUserId = $newEmployee->user?->id;
            if ($newAssigneeUserId) {
                TicketMute::where('ticket_id', $ticket->id)
                    ->where('user_id', $newAssigneeUserId)
                    ->delete();
            }

            // Suppress TicketObserver::updated's reassignment notification —
            // notifyAssignee below owns that path.
            TicketObserver::$skipReassignNotification = true;
            try {
                $ticket->update(['employee_id' => $employeeId]);
            } finally {
                TicketObserver::$skipReassignNotification = false;
            }

            if ($oldEmployee && $oldEmployee->id !== $employeeId) {
                Cache::forget("emp_perf_{$oldEmployee->id}");
                $oldEmployee->refreshPerformanceMetrics();
            }

            // Unified post-reassign block — applies to ALL pre-statuses so the
            // lifecycle is consistent: status = ASSIGNED, assigned_at = now(),
            // on_hold_since cleared, SLA recalculated from now(), breach cleared.
            // TicketObserver::saving() only stamps assigned_at when it is empty,
            // so the explicit now() here is never overwritten by the hook.
            $live = $ticket->fresh();

            $live->status      = TaskStatusEnum::ASSIGNED;
            $live->assigned_at = now();
            $live->on_hold_since = null;

            $priorityValue = is_object($live->priority)
                ? $live->priority->value
                : $live->priority;

            if ($live->area_id && $priorityValue !== null) {
                $policy = $this->slaService->resolvePolicy(
                    (int) $live->area_id,
                    $live->sub_area_id ? (int) $live->sub_area_id : null,
                    $live->unit_id ? (int) $live->unit_id : null,
                    $priorityValue,
                );
                if ($policy) {
                    $live->sla_deadline = $this->slaService->calculateDeadline($policy, now());
                }
            }

            $live->sla_breached = false;
            $live->save();

            // Write a status-transition history row only when status actually
            // changes — ASSIGNED → ASSIGNED is a no-op for the transition log.
            if ($statusBefore !== TaskStatusEnum::ASSIGNED) {
                TicketStatusHistory::create([
                    'ticket_id'   => $live->id,
                    'from_status' => $statusBefore->value,
                    'to_status'   => TaskStatusEnum::ASSIGNED->value,
                    'changed_by'  => $by->id,
                    'note'        => null,
                ]);
            }

            // Log the employee change as a from=to entry so the timeline
            // shows a 👤 Personel Değişikliği card.
            $this->logReassign($ticket->fresh(), $by, $fullNote);
            $this->notifyAssignee($ticket->fresh(), $by);
            $this->notifyCreatorOfReassignment($ticket->fresh(), $by, $oldEmployeeName, $newEmployeeName);

            // Notify group supervisor — skipped when supervisor is the actor,
            // the new assignee, or the creator (creator already gets
            // TicketReassignedNotification via notifyCreatorOfReassignment).
            $freshForSupervisor = $ticket->fresh();
            if ($freshForSupervisor->group_id) {
                $supervisor        = Group::find($freshForSupervisor->group_id)?->manager?->user;
                $newAssigneeUserId = $newEmployee->user?->id;
                if ($supervisor
                    && $supervisor->id !== $by->id
                    && (is_null($newAssigneeUserId) || $supervisor->id !== (int) $newAssigneeUserId)
                    && $supervisor->id !== (int) $freshForSupervisor->created_by) {
                    $supervisor->notify(
                        new TicketReassignedNotification($freshForSupervisor, $oldEmployeeName, $newEmployeeName, $by)
                    );
                }
            }

            return $ticket->fresh();
        });
    }

    /**
     * Send TicketReassignedNotification to the ticket's creator unless the
     * creator is also the actor or the new assignee (those people already
     * know — actor performed the action, new assignee gets the dedicated
     * TicketAssignedNotification).
     */
    private function notifyCreatorOfReassignment(Ticket $ticket, User $by, string $oldName, string $newName): void
    {
        $creatorId = (int) $ticket->created_by;
        if (!$creatorId || $creatorId === $by->id) {
            return;
        }

        // If the new assignee IS the creator, skip — they already got
        // TicketAssignedNotification via notifyAssignee/transition.
        $newAssigneeUserId = $ticket->employee?->user?->id;
        if ($newAssigneeUserId && $creatorId === (int) $newAssigneeUserId) {
            return;
        }

        $creator = User::find($creatorId);
        if (!$creator) {
            return;
        }

        $creator->notify(new TicketReassignedNotification($ticket, $oldName, $newName, $by));

        // Reassignment doesn't go through notifyParticipants (only creator
        // gets a DB notification, not the whole participant set), so fire
        // FCM directly here. Mirrors the actor-name-prefixed body used by
        // status-change and comment FCM payloads.
        // Gate FCM on the database preference to mirror notifyParticipants:
        // turning off the in-app bell for this type also silences the push.
        if ($creator->wantsNotification('ticket_reassigned', 'database')) {
            $actorName = $this->actorDisplayName($by);
            app(FcmService::class)->sendToUser(
                $creator,
                $ticket->ticket_no . ' • Personel Değişikliği',
                $actorName . ' tarafından yeniden atandı',
                url('/tickets/' . $ticket->id),
            );
        }
    }

    /**
     * Tag for the timeline renderer: lines starting with this prefix are
     * personnel-change events, not user comments. The renderer strips the
     * prefix when displaying the body and chooses a 👤 card instead of 💬.
     */
    public const REASSIGN_NOTE_PREFIX = '__reassign__:';

    private function logReassign(Ticket $ticket, User $by, string $note): void
    {
        $current = $ticket->status?->value;
        TicketStatusHistory::create([
            'ticket_id'   => $ticket->id,
            'from_status' => $current,
            'to_status'   => $current,
            'changed_by'  => $by->id,
            'note'        => self::REASSIGN_NOTE_PREFIX . $note,
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
            $this->pushAssignmentFcm($ticket, $assignee, $by);
        }
    }

    /**
     * Desktop push to the new assignee. Mirrors the actor-name-prefixed
     * body used elsewhere; gated on the user's database preference for
     * `ticket_assigned` so opting out of the bell silences the push too.
     */
    private function pushAssignmentFcm(Ticket $ticket, User $assignee, User $actor): void
    {
        if (!$assignee->wantsNotification('ticket_assigned', 'database')) {
            return;
        }

        $actorName = $this->actorDisplayName($actor);
        $area      = $ticket->area?->name ?? '';
        $priority  = $ticket->priority?->getLabel() ?? '';

        app(FcmService::class)->sendToUser(
            $assignee,
            $ticket->ticket_no . ' • Size Atandı',
            $actorName . ' tarafından atandı — ' . $area . ' / ' . $priority,
            url('/tickets/' . $ticket->id),
        );
    }

    /**
     * Fan out a "priority changed" alert to participants. Reuses
     * TicketCommentNotification for the bell entry (free-form body) and the
     * shared FCM path; mail is intentionally skipped — TicketCommentNotification's
     * via() returns ['database'] only, so notifyParticipants drives the database
     * + FCM channels and never the mail one.
     */
    public function notifyPriorityChange(
        Ticket $ticket,
        TaskPriorityEnum $oldPriority,
        TaskPriorityEnum $newPriority,
        User $actor,
    ): void {
        $oldLabel  = $oldPriority->getLabel();
        $newLabel  = $newPriority->getLabel();
        $actorName = $this->actorDisplayName($actor);
        $body      = $actorName . ' önceliği ' . $oldLabel . ' → ' . $newLabel . ' olarak değiştirdi';

        $this->notifyParticipants(
            $ticket,
            $actor,
            new TicketCommentNotification($ticket, $actor, $body),
            $ticket->ticket_no . ' • Öncelik Güncellendi',
            $body,
        );
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

        // Full participant set (creator + current assignee + anyone in
        // ticket_status_histories.changed_by) minus actor and muted users —
        // see notifyParticipants → getTicketParticipants. Mirrors the
        // visibility rule of the "Not Ekle" button on ViewTicket.
        $actorName = $this->actorDisplayName($by);
        $this->notifyParticipants(
            $ticket,
            $by,
            new TicketCommentNotification($ticket, $by, $note),
            $ticket->ticket_no . ' • Yeni Yorum',
            $actorName . ': ' . mb_substr($note, 0, 80),
        );

        return $history;
    }

    /**
     * Whether a comment may be edited by the given user.
     * Authors may edit their own comments within 10 minutes of posting.
     * Personnel-change entries (logReassign) are NOT editable — they're
     * system events even though the row shape (from==to) matches a comment.
     */
    public function canEditComment(TicketStatusHistory $entry, User $user): bool
    {
        if ($entry->changed_by !== $user->id) {
            return false;
        }

        if ($entry->from_status?->value !== $entry->to_status?->value) {
            return false; // it's a status change, not a pure comment
        }

        if (str_starts_with((string) $entry->note, self::REASSIGN_NOTE_PREFIX)) {
            return false; // reassignment system event
        }

        return $entry->created_at?->diffInMinutes(now()) < 10;
    }

    /**
     * Hard-delete a comment within the 10-min window. Re-applies the same
     * invariants as updateComment so a stale UI can't bypass them. Hard
     * delete (not soft) — a deleted comment never happened, so we don't
     * leave it in the timeline as a tombstone.
     *
     * @throws \DomainException same set as updateComment.
     */
    public function deleteComment(TicketStatusHistory $history, User $by): void
    {
        if ($history->changed_by !== $by->id) {
            throw new \DomainException('Yetkisiz işlem.');
        }

        if ($history->from_status?->value !== $history->to_status?->value) {
            throw new \DomainException('Durum değişikliği kayıtları silinemez.');
        }

        if (str_starts_with((string) $history->note, self::REASSIGN_NOTE_PREFIX)) {
            throw new \DomainException('Personel değişikliği kayıtları silinemez.');
        }

        if ($history->created_at?->diffInMinutes(now()) >= 10) {
            throw new \DomainException('Silme süresi doldu.');
        }

        $history->delete();
    }

    /**
     * Edit a comment's note in place. Re-applies canEditComment's contract
     * (author + 10-min window + not-a-status-event) so the rules can't be
     * bypassed by a stale Filament action mounted before the window expired.
     *
     * @throws \DomainException when the viewer isn't the author or the
     *                          edit window has elapsed.
     */
    public function updateComment(TicketStatusHistory $history, string $newNote, User $by): void
    {
        if ($history->changed_by !== $by->id) {
            throw new \DomainException('Yetkisiz işlem.');
        }

        if ($history->from_status?->value !== $history->to_status?->value) {
            throw new \DomainException('Durum değişikliği kayıtları düzenlenemez.');
        }

        if (str_starts_with((string) $history->note, self::REASSIGN_NOTE_PREFIX)) {
            throw new \DomainException('Personel değişikliği kayıtları düzenlenemez.');
        }

        if ($history->created_at?->diffInMinutes(now()) >= 10) {
            throw new \DomainException('Düzenleme süresi doldu.');
        }

        $history->update(['note' => $newNote]);

        $ticket = Ticket::find($history->ticket_id);
        if ($ticket) {
            $actorName = $this->actorDisplayName($by);
            $this->notifyParticipants(
                $ticket,
                $by,
                new TicketCommentNotification($ticket, $by, 'Not güncellendi: ' . mb_substr($newNote, 0, 100)),
                $ticket->ticket_no . ' • Not Güncellendi',
                $actorName . ' notu düzenledi',
            );
        }
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

    private function markResolved(Ticket $ticket): void
    {
        $ticket->resolved_at  = now();
        // Lock in the breach outcome at the moment of resolution so the
        // column reflects "did we resolve before the deadline?". This is the
        // historical record consumed by SlaComplianceTrendChart and the
        // PerformanceService 30-day metrics.
        $ticket->sla_breached = $ticket->sla_deadline && now()->isAfter($ticket->sla_deadline);
    }

    private function markClosed(Ticket $ticket, User $by): void
    {
        $ticket->closed_at = now();
        $ticket->closed_by = $by->id;
        // Use resolved_at as the reference timestamp when available so that
        // a ticket resolved on time is not flipped to breached when the
        // supervisor closes it days later (after the deadline has passed).
        $finalAt = $ticket->resolved_at ?? now();
        $ticket->sla_breached = $ticket->sla_deadline && $finalAt->isAfter($ticket->sla_deadline);
    }

    private function markCancelled(Ticket $ticket, User $by): void
    {
        $ticket->closed_at    = $ticket->closed_at ?? now();
        $ticket->closed_by    = $ticket->closed_by ?? $by->id;
        // Cancelled tickets are excluded from SLA — clear breach flag
        $ticket->sla_breached = false;
    }
}
