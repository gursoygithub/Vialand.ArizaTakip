<?php

namespace App\Models;

use App\Enums\BreachAttributionEnum;
use App\Enums\SlaCycleOutcomeEnum;
use App\Enums\TaskPriorityEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One SLA cycle of a ticket on one team. Targets and calendar are a
 * snapshot taken when the ticket reached the team.
 */
class TicketSlaCycle extends Model
{
    public const PAUSE_HOLD = 'hold';

    public const PAUSE_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    /**
     * Mirror the column defaults so a freshly created cycle is complete
     * without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'response_breached' => false,
        'resolution_breached' => false,
        'paused_minutes' => 0,
        'resolution_rejections' => 0,
        'success_threshold' => 80,
        'exempted' => false,
    ];

    protected $fillable = [
        'ticket_id',
        'cycle_no',
        'team_id',
        'priority',
        'response_minutes',
        'resolution_minutes',
        'success_threshold',
        'work_calendar_id',
        'started_at',
        'response_due_at',
        'resolution_due_at',
        'responded_at',
        'resolved_at',
        'resolution_rejections',
        'satisfaction_score',
        'satisfaction_comment',
        'rated_at',
        'paused_since',
        'pause_kind',
        'paused_minutes',
        'response_breached',
        'resolution_breached',
        'breached_at',
        'breach_attribution',
        'breach_employee_id',
        'exempted',
        'warning_sent_at',
        'breach_notified_at',
        'escalated_at',
        'outcome',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriorityEnum::class,
            'started_at' => 'immutable_datetime',
            'response_due_at' => 'immutable_datetime',
            'resolution_due_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'paused_since' => 'immutable_datetime',
            'breached_at' => 'immutable_datetime',
            'warning_sent_at' => 'immutable_datetime',
            'breach_notified_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'rated_at' => 'immutable_datetime',
            'exempted' => 'boolean',
            'satisfaction_score' => 'integer',
            'ended_at' => 'immutable_datetime',
            'response_breached' => 'boolean',
            'resolution_breached' => 'boolean',
            'breach_attribution' => BreachAttributionEnum::class,
            'outcome' => SlaCycleOutcomeEnum::class,
            'paused_minutes' => 'integer',
            'resolution_rejections' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function workCalendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class);
    }

    public function breachEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'breach_employee_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class)->orderBy('started_at');
    }

    public function exemptions(): HasMany
    {
        return $this->hasMany(SlaExemption::class);
    }

    /**
     * Sealed (on time / breached) and not exempted: the cycles that count
     * for performance.
     */
    public function scopeCountsForPerformance(Builder $query): Builder
    {
        return $query->whereIn('outcome', ['on_time', 'breached'])->where('exempted', false);
    }

    public function isPaused(): bool
    {
        return $this->paused_since !== null;
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Cycles still running (not ended).
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
