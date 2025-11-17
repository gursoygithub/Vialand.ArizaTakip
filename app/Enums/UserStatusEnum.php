<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum UserStatusEnum : int implements HasLabel, HasColor, HasIcon
{
    case ACTIVE = 1;
    case INACTIVE = 2;
    case SUSPENDED = 3;
    case LICENSED = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE            => __('ui.active'),
            self::INACTIVE          => __('ui.inactive'),
            self::SUSPENDED         => __('ui.suspended'),
            self::LICENSED          => __('ui.licensed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACTIVE            => 'success',
            self::INACTIVE          => 'warning',
            self::SUSPENDED         => 'danger',
            self::LICENSED          => 'secondary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::ACTIVE            => 'heroicon-o-check-circle',
            self::INACTIVE          => 'heroicon-o-ban',
            self::SUSPENDED         => 'heroicon-o-exclamation',
            self::LICENSED          => 'heroicon-o-identification',
        };
    }

    public function is($value): bool
    {
        return $this === $value;
    }

    public function isNot($value): bool
    {
        return $this !== $value;
    }
}
