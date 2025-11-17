<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ActiveStatusEnum: int implements HasLabel, HasColor, HasIcon
{
    case ACTIVE     = 1;
    case INACTIVE   = 0;

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE    => __('ui.active'),
            self::INACTIVE  => __('ui.inactive'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACTIVE    => 'success',
            self::INACTIVE  => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::ACTIVE    => 'heroicon-o-check-circle',
            self::INACTIVE  => 'heroicon-o-x-circle',
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
