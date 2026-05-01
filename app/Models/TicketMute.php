<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-(ticket, user) mute. When a row exists for (ticket_id, user_id),
 * that user is removed from the ticket's participant set and skipped by
 * the assignment observer's notification path. Rows are auto-deleted by
 * TicketService::reassign() when a muted user becomes the new assignee
 * so they always hear about the assignment they now own.
 */
class TicketMute extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
