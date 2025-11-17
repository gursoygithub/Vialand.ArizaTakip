<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum BooleanStatusEnum: int implements HasLabel, HasColor, HasIcon
{
    case YES = 1;
    case NO = 0;

    public function getLabel(): string
    {
        return match ($this) {
            self::YES       => __('ui.yes'),
            self::NO        => __('ui.no'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::YES       => 'success',
            self::NO        => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::YES       => 'heroicon-o-check-circle',
            self::NO        => 'heroicon-o-x-circle',
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
