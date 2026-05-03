<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mail Notifications
    |--------------------------------------------------------------------------
    |
    | When enabled, notifications that support both database and mail channels
    | (TicketAssigned, TicketClosed, SlaWarning, SlaBreached) will also dispatch
    | mail. Database notifications are always sent regardless of this flag.
    |
    */

    'mail_enabled' => env('NOTIFICATIONS_MAIL_ENABLED', false),
];
