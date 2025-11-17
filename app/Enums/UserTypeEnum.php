<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum UserTypeEnum: int implements HasLabel, HasIcon, HasColor
{
    case Manager = 1;
    case Employee = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::Manager         => __('ui.manager'),
            self::Employee        => __('ui.employee'),
            default            => __('ui.unknown'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manager         => 'primary',
            self::Employee        => 'secondary',
            default            => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Manager         => 'heroicon-o-user',
            self::Employee        => 'heroicon-o-users',
            default            => null,
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
