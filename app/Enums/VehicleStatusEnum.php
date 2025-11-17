<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum VehicleStatusEnum: int implements HasLabel, HasColor, HasIcon
{
    case LEFT      = 0;
    case INSIDE    = 1;
    case PENDING   = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::INSIDE    => __('ui.inside'),
            self::LEFT      => __('ui.left'),
            self::PENDING   => __('ui.pending'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::INSIDE    => 'success',
            self::LEFT      => 'danger',
            self::PENDING   => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::INSIDE    => 'heroicon-o-check-circle',
            self::LEFT      => 'heroicon-o-x-circle',
            self::PENDING   => 'heroicon-o-clock',
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
