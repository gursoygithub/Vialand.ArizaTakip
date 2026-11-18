<?php

namespace App\Models;

use App\Enums\TaskStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Support\Facades\Cache;
use App\Models\Employee;
use App\Models\GroupMember;

class Ticket extends Model implements HasMedia
{
    use HasFactory, Notifiable, SoftDeletes, InteractsWithMedia, LogsActivity;

    protected $table = 'tickets';

    protected $fillable = [
        'ticket_no',
        'title',
        'description',
        'ticket_type_id',
        'team_id',
        'custom_fields',
        'user_id',
        'area_id',
        'group_id',
        'sub_area_id',
        'unit_id',
        'type_id',
        'priority',
        'status',
        'employee_id',
        'assigned_person_type_id',
        'task_date',
        'resolution_notes',
        'reopen_reason',
        'reopened_by',
        'reopened_at',
        'sla_deadline',
        'sla_breached',
        'assigned_at',
        'resolved_at',
        'closed_at',
        'closed_by',
        'on_hold_since',
        'total_on_hold_minutes',
        'hold_reason',
        'closure_reason',
        'auto_close_at',
        'reopen_until',
        'created_by',
        'updated_by',
        'deleted_by',
        'updated_at',
    ];

    protected $casts = [
        'task_date'    => 'date',
        'sla_deadline' => 'datetime',
        'sla_warning_sent_at' => 'datetime',
        'assigned_at'  => 'datetime',
        'resolved_at'  => 'datetime',
        'closed_at'    => 'datetime',
        'on_hold_since' => 'datetime',
        'total_on_hold_minutes' => 'integer',
        'reopened_at'  => 'datetime',
        'sla_breached' => 'boolean',
        'custom_fields' => 'array',
        'hold_reason'  => \App\Enums\HoldReasonEnum::class,
        'auto_close_at' => 'datetime',
        'reopen_until' => 'datetime',
        'type_id'      => \App\Enums\TaskTypeEnum::class,
        'status'       => \App\Enums\TaskStatusEnum::class,
        'priority'     => \App\Enums\TaskPriorityEnum::class,
        'assigned_person_type_id' => \App\Enums\AssignedPersonTypeEnum::class,
    ];

    // --- Relationships ---

    public function ticketType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function slaCycles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TicketSlaCycle::class)->orderBy('cycle_no');
    }

    public function currentCycle(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TicketSlaCycle::class)->ofMany('cycle_no', 'max');
    }

    public function assignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TicketAssignment::class)->orderBy('started_at');
    }

    /**
     * People kept informed on the request (CC).
     */
    public function watchers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'ticket_watchers')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    public function isWatchedBy(?User $user): bool
    {
        $employeeId = $user?->employee?->id;

        return $employeeId !== null && $this->watchers()->whereKey($employeeId)->exists();
    }

    public function currentAssignment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TicketAssignment::class)->ofMany(['id' => 'max'], fn ($query) => $query->whereNull('ended_at'));
    }

    /**
     * Created through the type/team model (as opposed to the legacy
     * area/unit/group flow driven by TicketService and TicketObserver).
     */
    /**
     * Absolute link to the ticket's page in the panel: "Talepler" for team
     * tickets, the legacy "Arıza Talepleri" screen otherwise. Use this in every
     * notification, mail and push instead of building the path by hand.
     */
    public function panelUrl(): string
    {
        return url(($this->usesTeamWorkflow() ? '/requests/' : '/tickets/').$this->id);
    }

    public function usesTeamWorkflow(): bool
    {
        return $this->team_id !== null;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function subcontractorEmployee()
    {
        return $this->belongsTo(SubcontractorEmployee::class, 'employee_id', 'id')
            ->with('subcontractor');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id')
            ->with(['company', 'subAreas']);
    }

    public function subArea()
    {
        return $this->belongsTo(SubArea::class, 'sub_area_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id')
            ->with(['company', 'area', 'unit', 'manager', 'members']);
    }

    public function statusHistories()
    {
        return $this->hasMany(TicketStatusHistory::class)->orderBy('created_at', 'asc');
    }

    public function mutes()
    {
        return $this->hasMany(TicketMute::class);
    }

    public function isMutedBy(User $user): bool
    {
        return $this->mutes()->where('user_id', $user->id)->exists();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    // --- Media ---

    public function registerMediaCollections(): void
    {
        // The form caps uploads at 5 files via maxFiles(); the collection
        // itself isn't constrained so historical singleFile rows continue
        // to work and new tickets can attach a small batch.
        $this->addMediaCollection('task_attachments')
            ->useDisk('s3');
    }

    // --- SLA Accessors ---

    public function getTargetDateAttribute()
    {
        return Cache::remember("ticket_target_{$this->id}", 3600, function () {
            if ($this->sla_deadline) {
                return $this->sla_deadline;
            }

            $policy = SlaPolicy::where('area_id', $this->area_id)
                ->where('sub_area_id', $this->sub_area_id)
                ->where('unit_id', $this->unit_id)
                ->where('priority', $this->priority)
                ->first();

            if (!$policy) {
                $policy = SlaPolicy::where('area_id', $this->area_id)
                    ->where('priority', $this->priority)
                    ->whereNull('sub_area_id')
                    ->first();
            }

            if (!$policy || !$policy->deadline_minutes) {
                return null;
            }

            return $this->created_at->addMinutes($policy->deadline_minutes);
        });
    }

    public function getRemainingMinutes(): int
    {
        if (!$this->sla_deadline) {
            return 0;
        }
        return (int) now()->diffInMinutes($this->sla_deadline, false);
    }

    public function getSlaStatusLabel(): string
    {
        if (!$this->sla_deadline) {
            return '—';
        }
        if ($this->status === TaskStatusEnum::ON_HOLD) {
            return '⏸';
        }

        if ($this->status === TaskStatusEnum::CANCELLED) {
            return __('ui.ticket_cancelled');
        }

        $terminal = in_array($this->status, [
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::COMPLETED,
        ], true);

        if ($terminal) {
            // Compare resolved_at (or closed_at fallback) against the deadline
            // for a fully derived outcome — never trust the persisted column
            // for live rendering (the column is for historical audit only).
            $finalAt  = $this->resolved_at ?? $this->closed_at;
            $breached = $finalAt
                ? $finalAt->gt($this->sla_deadline)
                : now()->gt($this->sla_deadline);
            return $breached ? '✗ İhlalle çözüldü' : '✓ Zamanında çözüldü';
        }

        $remaining = $this->getRemainingMinutes();
        $abs = abs($remaining);
        $formatted = $abs >= 60
            ? floor($abs / 60) . 'sa ' . ($abs % 60) . 'dk'
            : $abs . 'dk';

        if ($remaining < 0) {
            return 'İhlal ' . $formatted;
        }
        return $formatted . ' kaldı';
    }

    public function getSlaPercentRemainingAttribute(): ?float
    {
        $deadline = $this->sla_deadline;
        if (!$deadline || !$this->created_at) {
            return null;
        }

        $total = $this->created_at->diffInSeconds($deadline);
        if ($total <= 0) {
            return 0;
        }

        $remaining = now()->diffInSeconds($deadline, false);

        return max(0, min(100, ($remaining / $total) * 100));
    }

    /**
     * Filter to tickets the system has flagged as SLA-breached. Backed by the
     * indexed `sla_breached` column, which is kept current by:
     *   - TicketObserver::saving — flips the flag whenever a ticket is saved
     *     and its deadline has passed (or clears it when resolved on time)
     *   - TicketService transition path — recomputes on RESOLVED/CLOSED/
     *     COMPLETED/CANCELLED transitions
     *   - CheckSlaBreaches job — sweeps untouched rows and sets the flag
     *     plus fires notifications
     *
     * For per-row *visual* rendering the UI still computes against now() vs
     * sla_deadline directly (see getSlaStatusLabel + the renderSla* helpers)
     * so countdown badges remain accurate between saves.
     */
    public function scopeSlaBreached(Builder $query): Builder
    {
        return $query->where('sla_breached', true);
    }

    // --- Team-model visibility (agreement §7) ---

    /**
     * Team tickets the user may see. Nobody sees requests that do not concern
     * them:
     *   - tickets they opened,
     *   - tickets assigned to them now or in the past,
     *   - tickets of teams they belong to (incl. the unassigned pool),
     *   - tickets of teams they supervise,
     *   - with `ticket.view.company`: tickets opened or handled by people of
     *     their companies,
     *   - with `ticket.view.all`: every team ticket.
     */
    public function scopeVisibleInTeamWorkflow(Builder $query, ?User $user): Builder
    {
        $query->whereNotNull('team_id');

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('super_admin') || $user->can('ticket.view.all')) {
            return $query;
        }

        $employeeId = $user->employee?->id;
        $companyIds = $user->can('ticket.view.company') ? $user->scopedCompanyIds() : [];

        return $query->where(function (Builder $q) use ($user, $employeeId, $companyIds): void {
            $q->where('created_by', $user->id);

            if ($employeeId) {
                $q->orWhere('employee_id', $employeeId)
                    ->orWhereHas('assignments', fn (Builder $a) => $a->where('employee_id', $employeeId))
                    ->orWhereIn('id', \Illuminate\Support\Facades\DB::table('ticket_watchers')->where('employee_id', $employeeId)->select('ticket_id'))
                    ->orWhereIn('team_id', \Illuminate\Support\Facades\DB::table('team_members')->where('employee_id', $employeeId)->select('team_id'))
                    ->orWhereIn('team_id', Team::query()->where('supervisor_employee_id', $employeeId)->select('id'));
            }

            if ($companyIds !== []) {
                $q->orWhereHas('createdBy.employee', fn (Builder $e) => $e->whereIn('company_id', $companyIds))
                    ->orWhereHas('employee', fn (Builder $e) => $e->whereIn('company_id', $companyIds));
            }
        });
    }

    // --- Permission-aware visibility scope ---

    /**
     * Restrict tickets to those visible to the given user, based on the custom
     * "Özel İzinler" permissions:
     *
     *   ticket.view.all   → no scope (sees everything)
     *   ticket.view.group → tickets in regions the user supervises
     *                       (User → employee → managedGroups → area_id)
     *   ticket.view.own   → tickets the user created OR is assigned to
     *                       (employee_id matches the user's Employee record)
     *
     * Priority: view.all > view.group > view.own. The legacy custom permission
     * Permission checks use Spatie's hasPermissionTo() — no role-name checks.
     */
    public function scopeVisibleBy(Builder $query, ?User $user): Builder
    {
        // No user → caller is responsible (background jobs, schedulers,
        // artisan commands). Returning the unfiltered query is correct here:
        // anything that reaches user-scoped code paths must pass an explicit
        // $user, and anything that operates over all tickets (SLA checker,
        // notifications, exports) deliberately wants no restriction.
        if (!$user) {
            return $query;
        }

        // 1. ticket.view.all → no scope.
        if ($user->hasPermissionTo('ticket.view.all')) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        // 2. ticket.view.group → tickets in areas of groups the user is a MEMBER
        //    of (via group_members) OR groups the user SUPERVISES (groups.employee_id),
        //    OR tickets they created, OR tickets assigned to them. Company filter uses
        //    OR so that group-membership/supervised areas in a foreign company are
        //    included alongside own-company tickets.
        if ($user->hasPermissionTo('ticket.view.group')) {
            $groupIds        = GroupMember::where('employee_id', $employeeId)->pluck('group_id');
            $memberAreaIds   = Group::whereIn('id', $groupIds)->pluck('area_id');
            $managedGroupIds = Group::where('employee_id', $employeeId)->pluck('id');
            $managedAreaIds  = Group::where('employee_id', $employeeId)->pluck('area_id');
            $areaIds         = $memberAreaIds->merge($managedAreaIds)->unique()->values();
            $companyIds      = $user->scopedCompanyIds();

            return $query
                ->where(function (Builder $q) use ($user, $employeeId, $areaIds, $managedGroupIds, $companyIds) {
                    // Group/area membership — company-agnostic (member + amir paths).
                    if ($areaIds->isNotEmpty()) {
                        $q->whereIn('area_id', $areaIds);
                    }
                    if ($managedGroupIds->isNotEmpty()) {
                        $q->orWhereIn('group_id', $managedGroupIds);
                    }
                    // Identity: creator and assignee always retain access regardless of company.
                    $q->orWhere('created_by', $user->id);
                    if ($employeeId) {
                        $q->orWhere('employee_id', $employeeId);
                    }
                    // Own-company tickets not already covered by area/group membership.
                    if (!empty($companyIds)) {
                        $q->orWhereHas('area', fn (Builder $cq) => $cq->whereIn('company_id', $companyIds));
                    }
                });
        }

        // 3. ticket.view.own → own + assigned tickets, restricted to accessible
        //    companies plus group-membership areas (which may span companies).
        // 4. NO relevant permission → SAME as ticket.view.own. Navigation
        //    visibility is gated separately by Shield's view_any_ticket; this
        //    scope is only reached when the user is already in the panel, so
        //    fall back to a non-empty (but minimal) result instead of 1=0.
        $companyIds   = $user->scopedCompanyIds();
        $groupAreaIds = $employeeId
            ? Group::whereHas('members', fn ($q) => $q->where('employee_id', $employeeId))
                ->pluck('area_id')
                ->toArray()
            : [];

        return $query
            ->where(function (Builder $q) use ($user, $employeeId, $companyIds, $groupAreaIds) {
                // Identity: creator and assignee always retain access regardless of company.
                $q->where('created_by', $user->id);
                if ($employeeId) {
                    $q->orWhere('employee_id', $employeeId);
                }
                // Company/group-area scoped tickets beyond own/assigned.
                // Only added when there is a meaningful filter to avoid an unbounded result.
                if (!empty($companyIds) || !empty($groupAreaIds)) {
                    $q->orWhere(function (Builder $scoped) use ($companyIds, $groupAreaIds) {
                        if (!empty($companyIds)) {
                            $scoped->whereHas('area', fn (Builder $a) => $a->whereIn('company_id', $companyIds));
                        }
                        if (!empty($groupAreaIds)) {
                            $scoped->orWhereIn('area_id', $groupAreaIds);
                        }
                    });
                }
            });
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            // Only auto-fill created_by if the caller didn't set one explicitly.
            // Lets factories and admin tools provide the real author while still
            // defaulting to the authenticated user in normal flows.
            if (empty($ticket->created_by)) {
                $ticket->created_by = auth()->id();
            }

            if (empty($ticket->ticket_no)) {
                $year  = now()->year;
                $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $ticket->ticket_no = 'TKT-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
            }

            if (empty($ticket->status)) {
                // Promote to ASSIGNED when an employee is already attached so
                // the observer's assigned_at stamp and the history row both
                // reflect the correct initial status. Mirrors the observer's
                // own check but runs first (model boot beats observer order).
                $ticket->status = $ticket->employee_id
                    ? TaskStatusEnum::ASSIGNED
                    : TaskStatusEnum::OPEN;
            }
        });

        static::saved(function (Ticket $ticket) {
            Cache::forget('dashboard_stats_overview');
            Cache::forget("ticket_target_{$ticket->id}");

            if (!$ticket->wasChanged([
                'sla_breached',
                'resolved_at',
                'employee_id',
            ])) {
                return;
            }

            // Skip per-ticket recalc when the bulk job is processing —
            // the job will recalculate once per unique employee at the end.
            if (\App\Jobs\CheckSlaBreaches::$inProgress) {
                return;
            }

            if ($ticket->employee_id) {
                $currentEmployee = Employee::find($ticket->employee_id);
                if ($currentEmployee) {
                    Cache::forget("emp_perf_{$ticket->employee_id}");
                    $currentEmployee->refreshPerformanceMetrics();
                }
            }
        });

        static::updating(function (Ticket $ticket) {
            $ticket->updated_by = auth()->id();
            // Assignment notification is dispatched by TicketObserver::updating
            // through TicketAssignedNotification (notifies the User, which is
            // what the panel bell reads). No legacy Employee-direct dispatch.
        });

        static::deleting(function (Ticket $ticket) {
            $ticket->deleted_by = auth()->id();
            $ticket->save();
        });
    }

    protected static $logName = 'tickets';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }
}
