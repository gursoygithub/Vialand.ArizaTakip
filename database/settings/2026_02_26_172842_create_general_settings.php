<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Veritabanına başlangıç değerini (80) mühürlüyoruz
        $this->migrator->add('general.sla_threshold', 80.0);
    }
};
