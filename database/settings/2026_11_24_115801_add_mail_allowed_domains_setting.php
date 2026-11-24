<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Mail goes only to these company domains; anything else (gmail,
     * hotmail, typos) is skipped and logged. Managed on Sistem Ayarları.
     */
    public function up(): void
    {
        $this->migrator->add('general.mail_allowed_domains', [
            'gursoygrup.com.tr',
            'vialand.com',
            'guryapi.com',
            'muesseseyemek.com',
            'blackgreen.com.tr',
            'veneziamegaoutlet.com',
            'camlicaturizm.com',
            'bgmaden.com',
            'erkaymaden.com',
            'bgferrokrom.com',
        ]);
    }
};
