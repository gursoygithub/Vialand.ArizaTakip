<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Hours after a breach before the still-open ticket is escalated to
     * users holding `ticket.sla.escalation`. 0 switches escalation off.
     */
    public function up(): void
    {
        $this->migrator->add('general.sla_escalation_hours', 4);
    }
};
