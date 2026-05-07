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
use App\Models\GroupMember;

class Ticket extends Model implements HasMedia
{
    use HasFactory, Notifiable, SoftDeletes, InteractsWithMedia, LogsActivity;

    protected $table = 'tickets';

    protected $fillable = [
        'ticket_no',
        'title',
        'description',
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
        'created_by',
        'updated_by',
        'deleted_by',
        'updated_at',
    ];

    protected $casts = [
        'task_date'    => 'date',
        'sla_deadline' => 'datetime',
        'assigned_at'  => 'datetime',
        'resolved_at'  => 'datetime',
        'closed_at'    => 'datetime',
        'on_hold_since' => 'datetime',
        'total_on_hold_minutes' => 'integer',
        'reopened_at'  => 'datetime',
        'sla_breached' => 'boolean',
        'type_id'      => \App\Enums\TaskTypeEnum::class,
        'status'       => \App\Enums\TaskStatusEnum::class,
        'priority'     => \App\Enums\TaskPriorityEnum::class,
        'assigned_person_type_id' => \App\Enums\AssignedPersonTypeEnum::class,
    ];

    // --- Relationships ---

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

        $terminal = in_array($this->status, [
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::COMPLETED,
            TaskStatusEnum::CANCELLED,
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
     * `view_all_tasks` is treated as a synonym for `ticket.view.all` (consolidation).
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

        // 1. ticket.view.all (or legacy view_all_tasks) → no scope.
        if ($user->hasPermissionTo('ticket.view.all') || $user->hasPermissionTo('view_all_tasks')) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        // 2. ticket.view.group → tickets in areas of groups the user is a MEMBER
        //    of (via group_members), OR tickets they created, OR tickets assigned
        //    to them. Company filter uses OR so that group-membership areas in a
        //    foreign company are included alongside own-company tickets.
        if ($user->hasPermissionTo('ticket.view.group')) {
            $groupIds   = GroupMember::where('employee_id', $employeeId)->pluck('group_id');
            $areaIds    = Group::whereIn('id', $groupIds)->pluck('area_id');
            $companyIds = $user->scopedCompanyIds();

            return $query
                ->where(function (Builder $q) use ($user, $employeeId, $areaIds) {
                    $q->whereIn('area_id', $areaIds)
                      ->orWhere('created_by', $user->id);
                    if ($employeeId) {
                        $q->orWhere('employee_id', $employeeId);
                    }
                })
                ->when(!empty($companyIds), fn (Builder $q) => $q->where(
                    function (Builder $aq) use ($companyIds, $areaIds) {
                        $aq->whereHas('area', fn (Builder $cq) => $cq->whereIn('company_id', $companyIds));
                        if ($areaIds->isNotEmpty()) {
                            $aq->orWhereIn('area_id', $areaIds);
                        }
                    }
                ));
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
            ->where(function (Builder $q) use ($user, $employeeId) {
                $q->where('created_by', $user->id);
                if ($employeeId) {
                    $q->orWhere('employee_id', $employeeId);
                }
            })
            ->when(!empty($companyIds) || !empty($groupAreaIds), fn (Builder $q) =>
                $q->where(function (Builder $aq) use ($companyIds, $groupAreaIds) {
                    if (!empty($companyIds)) {
                        $aq->whereHas('area', fn (Builder $cq) => $cq->whereIn('company_id', $companyIds));
                    }
                    if (!empty($groupAreaIds)) {
                        $aq->orWhereIn('area_id', $groupAreaIds);
                    }
                })
            );
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
                $ticket->status = TaskStatusEnum::OPEN;
            }
        });

        static::saved(function (Ticket $ticket) {
            Cache::forget('dashboard_stats_overview');
            Cache::forget("ticket_target_{$ticket->id}");

            if ($ticket->employee_id && $ticket->employee) {
                Cache::forget("emp_perf_{$ticket->employee_id}");
                $ticket->employee->refreshPerformanceMetrics();
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
