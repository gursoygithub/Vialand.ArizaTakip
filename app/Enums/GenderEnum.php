<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GenderEnum: int implements HasLabel, HasColor
{
    case MALE = 1;
    case FEMALE = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::MALE => __('ui.male'),
            self::FEMALE => __('ui.female'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::MALE => 'info',
            self::FEMALE => 'danger',
        };
    }
}
