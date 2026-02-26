<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{

    public float $sla_threshold; // Değişkenimizi buraya ekledik

    public static function group(): string
    {
        return 'general'; // Grubu 'general' yaptık
    }
}