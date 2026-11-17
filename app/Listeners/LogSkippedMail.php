<?php

namespace App\Listeners;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;

/**
 * Laravel's mail channel quietly drops a notification when the recipient has
 * no address. Leave a trace so missing e-mails can be found and fixed.
 */
class LogSkippedMail
{
    public function handle(NotificationSending $event): void
    {
        if ($event->channel !== 'mail' || ! method_exists($event->notifiable, 'routeNotificationFor')) {
            return;
        }

        if (filled($event->notifiable->routeNotificationFor('mail', $event->notification))) {
            return;
        }

        Log::warning('Mail skipped: recipient has no e-mail address.', [
            'notification' => $event->notification::class,
            'notifiable' => $event->notifiable::class,
            'notifiable_id' => $event->notifiable->getKey(),
            'name' => $event->notifiable->name ?? null,
        ]);
    }
}
