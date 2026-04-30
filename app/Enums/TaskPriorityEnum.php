<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TaskPriorityEnum: int implements HasLabel, HasColor, HasIcon
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Urgent = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => __('ui.low'),
            self::Medium => __('ui.medium'),
            self::High => __('ui.high'),
            self::Urgent => __('ui.urgent'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'success',
            self::Medium => 'primary',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Low => 'heroicon-o-arrow-down-circle',
            self::Medium => 'heroicon-o-minus-circle',
            self::High => 'heroicon-o-arrow-up-circle',
            self::Urgent => 'heroicon-o-exclamation-circle',
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
