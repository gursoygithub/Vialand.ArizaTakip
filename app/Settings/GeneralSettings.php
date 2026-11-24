<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{

    public float $sla_threshold; // Değişkenimizi buraya ekledik

    /**
     * Days a ticket may wait in "Çözüldü" for the requester's confirmation
     * before it closes automatically. Copied onto the ticket when resolved.
     */
    public int $auto_close_days;

    /**
     * Days after closing during which the requester may reopen the ticket.
     * Copied onto the ticket when closed.
     */
    public int $reopen_window_days;

    /**
     * Hours after an SLA breach before a still-open team ticket is escalated
     * to users with `ticket.sla.escalation`. 0 = off.
     */
    public int $sla_escalation_hours;

    /**
     * Company e-mail domains mail may be sent to (lower case, no "@").
     *
     * @var array<int, string>
     */
    public array $mail_allowed_domains;

    public static function group(): string
    {
        return 'general'; // Grubu 'general' yaptık
    }
}