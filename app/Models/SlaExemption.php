<?php

namespace App\Models;

use App\Enums\ExemptionReasonEnum;
use App\Enums\ExemptionStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A request to take one breached SLA cycle out of performance figures.
 */
class SlaExemption extends Model
{
    use LogsActivity;

    protected $fillable = [
        'ticket_sla_cycle_id',
        'ticket_id',
        'requested_by',
        'reason',
        'note',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'reason' => ExemptionReasonEnum::class,
            'status' => ExemptionStatusEnum::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(TicketSlaCycle::class, 'ticket_sla_cycle_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->useLogName('sla_exemptions');
    }
}
