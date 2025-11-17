<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TaskStatusEnum: int implements HasLabel, HasColor, HasIcon
{
    case PENDING = 0;
    case COMPLETED = 1;
    case WINTER_MAINTENANCE = 2;


    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING          => __('ui.pending'),
            self::COMPLETED        => __('ui.completed'),
            self::WINTER_MAINTENANCE => __('ui.winter_maintenance'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING          => 'warning',
            self::COMPLETED        => 'success',
            self::WINTER_MAINTENANCE => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PENDING          => 'heroicon-o-clock',
            self::COMPLETED        => 'heroicon-o-check-circle',
            self::WINTER_MAINTENANCE => 'heroicon-o-lifebuoy',
        };
    }

    public function is($status): bool
    {
        return $this === $status;
    }

    public function isNot($status): bool
    {
        return $this !== $status;
    }
}
