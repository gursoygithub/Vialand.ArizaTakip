<?php

namespace App\Models;

use App\Enums\HoldReasonEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period a team ticket spent on hold: who put it on hold, why, and
 * how many working minutes it lasted (set when the hold ends).
 */
class TicketHold extends Model
{
    protected $fillable = [
        'ticket_id',
        'team_id',
        'employee_id',
        'reason',
        'note',
        'started_at',
        'ended_at',
        'working_minutes',
        'alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => HoldReasonEnum::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'alerted_at' => 'immutable_datetime',
            'working_minutes' => 'integer',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
