<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'from_status',
        'to_status',
        'changed_by',
        'note',
        'created_at',
    ];

    protected $casts = [
        'from_status' => \App\Enums\TaskStatusEnum::class,
        'to_status'   => \App\Enums\TaskStatusEnum::class,
        'created_at'  => 'datetime',
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
