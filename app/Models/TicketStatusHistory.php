<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketStatusHistory extends Model
{
    // Auto-managed timestamps. created_at is the immutable audit point;
    // updated_at is bumped only when a comment row is edited via
    // TicketService::updateComment (status / reassign rows are never
    // mutated, so their updated_at stays equal to created_at).
    public $timestamps = true;

    protected $fillable = [
        'ticket_id',
        'from_status',
        'to_status',
        'changed_by',
        'note',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'from_status' => \App\Enums\TaskStatusEnum::class,
        'to_status'   => \App\Enums\TaskStatusEnum::class,
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    protected static function booted(): void
    {
        static::creating(function (TicketStatusHistory $history) {
            if (empty($history->created_at)) {
                $history->created_at = now();
            }
        });
    }
}
