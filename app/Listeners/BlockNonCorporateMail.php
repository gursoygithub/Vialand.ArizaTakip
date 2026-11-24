<?php

namespace App\Listeners;

use App\Support\CompanyEmail;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;

/**
 * Notification mail only goes to company addresses (Sistem Ayarları →
 * izinli alan adları). A personal or mistyped address is skipped and
 * logged; the in-app bell of the same notification is unaffected.
 */
class BlockNonCorporateMail
{
    public function handle(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail' || ! method_exists($event->notifiable, 'routeNotificationFor')) {
            return null;
        }

        $address = $event->notifiable->routeNotificationFor('mail', $event->notification);
        if (is_array($address)) {
            $first = array_key_first($address);
            $address = is_string($first) ? $first : ($address[$first] ?? null);
        }

        if (blank($address) || CompanyEmail::isCorporate((string) $address)) {
            return null;
        }

        Log::warning('Mail skipped: address is not a company address.', [
            'notification' => $event->notification::class,
            'notifiable' => $event->notifiable::class,
            'notifiable_id' => method_exists($event->notifiable, 'getKey') ? $event->notifiable->getKey() : null,
            'address' => $address,
        ]);

        return false;
    }
}
