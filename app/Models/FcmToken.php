<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (user, browser/device) FCM registration. token is the
 * actual FCM device token; token_hash is a sha256 of it used as the
 * uniqueness key (MySQL can't index full-length tokens directly under
 * the default 191/255-byte limit).
 *
 * is_active is flipped to false by FcmService when a send fails with a
 * permanent error (token expired/invalid). Inactive rows are skipped
 * by sendToUser; users replacing their device get a fresh row via
 * upsertForUser.
 */
class FcmToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'token_hash',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Idempotent register with exclusive token ownership.
     *
     * Firebase returns the same FCM token for a given browser regardless
     * of which user is logged in — so a single token can end up with rows
     * under multiple user_ids when several users log in on the same
     * browser. When that happened, FcmService::sendToUser would deliver
     * notifications meant for user A to the browser that's now user B's
     * session.
     *
     * Each call to this method enforces "this token belongs to exactly
     * one user right now" by deactivating the same token (matched by
     * sha256 hash) under every OTHER user_id before upserting the row
     * for the caller. Old rows stick around — useful for audit — but
     * is_active=false stops sendToUser from picking them up.
     */
    public static function upsertForUser(int $userId, string $token): self
    {
        $hash = hash('sha256', $token);

        // Token-takeover: deactivate this token for any other user_id.
        self::query()
            ->where('token_hash', $hash)
            ->where('user_id', '!=', $userId)
            ->update(['is_active' => false]);

        return self::updateOrCreate(
            ['user_id' => $userId, 'token_hash' => $hash],
            ['token' => $token, 'is_active' => true],
        );
    }
}
