<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-user opt-in/out for each notification type and each delivery channel
 * (database / mail). One row per (user_id, notification_type) — unique
 * indexed. Absence of a row means "use the default" (true), so users only
 * carry rows for the types they've actively touched in the UI.
 */
class UserNotificationPreference extends Model
{
    use HasFactory;

    public const TYPES = [
        'ticket_participant',
        'team_hold',
        'team_hold_overdue',
    ];

    protected $fillable = [
        'user_id',
        'notification_type',
        'mail_enabled',
        'database_enabled',
    ];

    protected $casts = [
        'mail_enabled'     => 'boolean',
        'database_enabled' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
