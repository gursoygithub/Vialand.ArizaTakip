<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CardStatusEnum: int implements HasLabel, HasColor, HasIcon
{
    case FREE      = 0;
    case OCCUPIED  = 1;
    case RETURNED  = 2;
    case DAMAGED   = 3;
    public function getLabel(): string
    {
        return match ($this) {
            self::FREE      => __('ui.free'),
            self::OCCUPIED  => __('ui.occupied'),
            self::RETURNED  => __('ui.returned'),
            self::DAMAGED   => __('ui.damaged'),
            default      => __('ui.unknown'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FREE, self::RETURNED  => 'success',
            self::OCCUPIED  => 'danger',
            self::DAMAGED   => 'warning',
            default      => 'secondary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::FREE, self::RETURNED  => 'heroicon-o-check-circle',
            self::OCCUPIED  => 'heroicon-o-x-circle',
            self::DAMAGED => 'heroicon-o-exclamation-circle',
            default => 'heroicon-o-question-mark-circle',
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
