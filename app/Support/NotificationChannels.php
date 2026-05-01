<?php

namespace App\Support;

/**
 * Shared via() resolver for notifications that respect per-user
 * UserNotificationPreference. Keeps each notification's via() to
 * a one-liner.
 */
class NotificationChannels
{
    /**
     * Build the via() channel list for $notifiable + $type:
     *  - 'database' if the user has it enabled (or no preference row)
     *  - 'mail' if config('notifications.mail_enabled') is true AND
     *           the user has mail enabled for this type
     *
     * Notifiables without a wantsNotification helper (e.g. Employee
     * targets in legacy code paths) get database+mail by default
     * with the global mail_enabled gate.
     */
    public static function resolve(object $notifiable, string $type): array
    {
        $channels = [];

        if (method_exists($notifiable, 'wantsNotification')) {
            if ($notifiable->wantsNotification($type, 'database')) {
                $channels[] = 'database';
            }
            if (config('notifications.mail_enabled', false)
                && $notifiable->wantsNotification($type, 'mail')) {
                $channels[] = 'mail';
            }
            return $channels;
        }

        // Fallback for non-User notifiables — old behavior.
        $channels[] = 'database';
        if (config('notifications.mail_enabled', false)) {
            $channels[] = 'mail';
        }
        return $channels;
    }
}
