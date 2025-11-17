<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum VehicleTypeEnum: int implements HasLabel
{
    case PERSONAL_CAR = 0;
    case WORK_MACHINE_HEAVY = 1;
    case MOTORCYCLE   = 2;
    case OTHER        = 3;

//    case PERSONAL_CAR = 0;
//    case MOTORCYCLE   = 1;
//    case OTHER        = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::PERSONAL_CAR => __('ui.personal_car'),
            self::WORK_MACHINE_HEAVY => __('ui.work_machine_heavy'),
            self::MOTORCYCLE   => __('ui.motorcycle'),
            self::OTHER        => __('ui.other'),
            default            => __('ui.unknown'),
        };
    }

    public function is($type): bool
    {
        return $this === $type;
    }
    public function isNot($type): bool
    {
        return $this !== $type;
    }

}
