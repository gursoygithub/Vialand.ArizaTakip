<?php

namespace App\Models;

use App\Enums\TaskPriorityEnum;
use App\Models\Concerns\RecordsAuthors;
use App\Services\SupervisorRoleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A team serves exactly one ticket type. Supervisor and members may belong
 * to any company. Without a work calendar the SLA clock runs 7/24.
 */
class Team extends Model
{
    use HasFactory, LogsActivity, RecordsAuthors, SoftDeletes;

    /**
     * Suggested SLA targets for a new team, in minutes: [response, resolution],
     * keyed by TaskPriorityEnum value.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    public const DEFAULT_SLA_MINUTES = [
        1 => [240, 2880],
        2 => [120, 1440],
        3 => [60, 480],
        4 => [15, 120],
    ];

    protected $fillable = [
        'ticket_type_id',
        'name',
        'description',
        'supervisor_employee_id',
        'work_calendar_id',
        'allow_self_assign',
        'hold_alert_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allow_self_assign' => 'boolean',
            'hold_alert_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /**
     * Whoever becomes or stops being a supervisor gets or loses the
     * "Ekip Amiri" role (SupervisorRoleService).
     */
    protected static function booted(): void
    {
        static::saved(function (Team $team): void {
            if (! $team->wasRecentlyCreated && ! $team->wasChanged('supervisor_employee_id')) {
                return;
            }

            $roles = app(SupervisorRoleService::class);
            $roles->syncEmployee($team->supervisor_employee_id);
            $roles->syncEmployee($team->getPrevious()['supervisor_employee_id'] ?? null);
        });

        static::deleted(fn (Team $team) => app(SupervisorRoleService::class)->syncEmployee($team->supervisor_employee_id));
        static::restored(fn (Team $team) => app(SupervisorRoleService::class)->syncEmployee($team->supervisor_employee_id));
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id');
    }

    public function workCalendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'team_members')->withTimestamps();
    }

    public function slaPolicies(): HasMany
    {
        return $this->hasMany(TeamSlaPolicy::class)->orderBy('priority');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function slaPolicyFor(TaskPriorityEnum $priority): ?TeamSlaPolicy
    {
        return $this->slaPolicies->firstWhere('priority', $priority);
    }

    public function runsAroundTheClock(): bool
    {
        return $this->workCalendar === null || $this->workCalendar->is_24_7;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->useLogName('teams');
    }
}
