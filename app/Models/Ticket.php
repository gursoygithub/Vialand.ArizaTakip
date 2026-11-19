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
     * Absolute link to the request's page in the panel. Use this in every
     * notification, mail and push instead of building the path by hand.
     */
    public function panelUrl(): string
    {
        return url('/requests/'.$this->id);
    }

    /**
     * Created through the type/team model. Rows from the old system (no
     * team) stay in the table as an archive but are never shown or changed.
     */

    public function usesTeamWorkflow(): bool
    {
        return $this->team_id !== null;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
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
                $ticket->status = $ticket->employee_id
                    ? TaskStatusEnum::ASSIGNED
                    : TaskStatusEnum::OPEN;
            }
        });

        static::updating(function (Ticket $ticket) {
            $ticket->updated_by = auth()->id();
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
