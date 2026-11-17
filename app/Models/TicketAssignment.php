<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period during which one employee held a ticket. Handing a ticket over
 * closes the current period and opens a new one; the SLA cycle carries on.
 */
class TicketAssignment extends Model
{
    public const END_REASSIGNED = 'reassigned';

    public const END_CLOSED = 'closed';

    public const END_CANCELLED = 'cancelled';

    public const END_REJECTED = 'rejected';

    public const END_TRANSFERRED = 'transferred';

    protected $fillable = [
        'ticket_id',
        'ticket_sla_cycle_id',
        'employee_id',
        'assigned_by',
        'self_assigned',
        'started_at',
        'ended_at',
        'end_reason',
        'remaining_ratio_at_start',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'self_assigned' => 'boolean',
            'remaining_ratio_at_start' => 'float',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(TicketSlaCycle::class, 'ticket_sla_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
