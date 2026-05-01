<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

/**
 * Wraps Firebase Cloud Messaging dispatch. Each user can have multiple
 * tokens (laptop, phone, multiple browsers); we send to all active ones
 * and deactivate any that fail with a permanent token error.
 *
 * Configuration:
 *   - services.firebase.credentials → relative path to the JSON key file
 *     under storage/. The file is gitignored; place it manually in the
 *     environment that should send pushes. If the file is missing the
 *     service silently no-ops every send (we don't want to crash ticket
 *     creation just because FCM isn't configured locally).
 */
class FcmService
{
    private ?Messaging $messaging = null;

    private function messaging(): ?Messaging
    {
        if ($this->messaging) {
            return $this->messaging;
        }

        $relative = config('services.firebase.credentials');
        if (!$relative) {
            return null;
        }

        // Path is relative to project root, but kreait expects an absolute
        // path. storage_path('app/private/...') if user gave 'storage/...'.
        $absolute = base_path($relative);
        if (!is_file($absolute)) {
            return null;
        }

        try {
            $factory = (new Factory)->withServiceAccount($absolute);
            $this->messaging = $factory->createMessaging();
        } catch (\Throwable $e) {
            Log::warning('FCM factory init failed', ['error' => $e->getMessage()]);
            return null;
        }

        return $this->messaging;
    }

    public function sendToUser(User $user, string $title, string $body, string $url = '/'): void
    {
        $messaging = $this->messaging();
        if (!$messaging) {
            return; // not configured — no-op
        }

        $tokens = FcmToken::where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('token');

        if ($tokens->isEmpty()) {
            return;
        }

        foreach ($tokens as $token) {
            try {
                // kreait/firebase-php 8.x dropped the static
                // CloudMessage::withTarget('token', $token) constructor;
                // build via ::new() and ->withToken() instead.
                $message = CloudMessage::new()
                    ->withToken($token)
                    ->withNotification(FcmNotification::create($title, $body))
                    ->withData([
                        'title' => $title,
                        'body'  => $body,
                        'url'   => $url,
                    ]);

                $messaging->send($message);
            } catch (\Throwable $e) {
                // Permanent token error → deactivate so we don't keep
                // hammering it on every notification.
                FcmToken::where('user_id', $user->id)
                    ->where('token', $token)
                    ->update(['is_active' => false]);

                Log::warning('FCM send failed', [
                    'user_id' => $user->id,
                    'token'   => substr($token, 0, 20) . '...',
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param Collection<int, User> $users
     */
    public function sendToUsers(Collection $users, string $title, string $body, string $url = '/'): void
    {
        foreach ($users as $user) {
            $this->sendToUser($user, $title, $body, $url);
        }
    }
}
