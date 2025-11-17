<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum VisitorStatusEnum: int implements HasLabel, HasColor, HasIcon
{
    case INSIDE     = 1;
    case LEFT       = 0;

    public function getLabel(): string
    {
        return match ($this) {
            self::INSIDE    => __('ui.inside'),
            self::LEFT      => __('ui.left'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::INSIDE    => 'success',
            self::LEFT      => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::INSIDE    => 'heroicon-o-check-circle',
            self::LEFT      => 'heroicon-o-x-circle',
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
