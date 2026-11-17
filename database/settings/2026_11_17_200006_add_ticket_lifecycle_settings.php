<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Agreement decisions 5 and 6: auto-close after X days in "Çözüldü",
     * reopen allowed within Y days of closing. Values are copied onto each
     * ticket when it is resolved/closed, so later changes never rewrite
     * history (decision 17).
     */
    public function up(): void
    {
        $this->migrator->add('general.auto_close_days', 3);
        $this->migrator->add('general.reopen_window_days', 7);
    }
};
