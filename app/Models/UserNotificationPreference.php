<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-user opt-in/out for each notification type and each delivery channel
 * (database / mail). One row per (user_id, notification_type) — unique
 * indexed. Absence of a row means "use the default" (true), so users only
 * carry rows for the types they've actively touched in the UI.
 *
 * Critical types (ticket_assigned, sla_breach) ignore database_enabled at
 * the UI level — the toggle is rendered disabled — but the column is
 * still here so the row shape stays uniform.
 */
class UserNotificationPreference extends Model
{
    use HasFactory;

    public const TYPES = [
        'ticket_assigned',
        'ticket_participant',
        'ticket_closed',
        'ticket_reopened',
        'ticket_reassigned',
        'sla_warning',
        'sla_breach',
    ];

    /**
     * Notification types whose database (in-app) channel is mandatory and
     * cannot be opted out of. Mail is still per-user.
     */
    public const ALWAYS_ON_DATABASE = [
        'ticket_assigned',
        'ticket_cancelled',
        'sla_breach',
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
