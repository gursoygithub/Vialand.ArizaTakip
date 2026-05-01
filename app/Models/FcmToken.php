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
     * Idempotent register: creates the row when new, reactivates if the
     * same token had been deactivated previously.
     */
    public static function upsertForUser(int $userId, string $token): self
    {
        $hash = hash('sha256', $token);

        return self::updateOrCreate(
            ['user_id' => $userId, 'token_hash' => $hash],
            ['token' => $token, 'is_active' => true],
        );
    }
}
