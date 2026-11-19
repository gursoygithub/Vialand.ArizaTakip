<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-(ticket, user) mute: the user stops getting the request's
 * informational notices (notes, minor steps). Notices that ask them to act
 * are never muted.
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
