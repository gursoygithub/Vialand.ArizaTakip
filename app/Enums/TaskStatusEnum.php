<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TaskStatusEnum: int implements HasLabel, HasColor, HasIcon
{
    // Legacy statuses (kept for backward compat with existing records)
    case PENDING = 0;
    case COMPLETED = 1;
    case WINTER_MAINTENANCE = 2;

    // New ticket lifecycle statuses (REFORM.md §5.1)
    case OPEN = 10;
    case ASSIGNED = 11;
    case IN_PROGRESS = 12;
    case RESOLVED = 13;
    case CLOSED = 14;
    case ON_HOLD = 15;
    case CANCELLED = 16;

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING          => __('ui.pending'),
            self::COMPLETED        => __('ui.completed'),
            self::WINTER_MAINTENANCE => __('ui.winter_maintenance'),
            self::OPEN             => __('ui.open'),
            self::ASSIGNED         => __('ui.assigned'),
            self::IN_PROGRESS      => __('ui.in_progress'),
            self::RESOLVED         => __('ui.resolved'),
            self::CLOSED           => __('ui.closed'),
            self::ON_HOLD          => __('ui.on_hold'),
            self::CANCELLED        => __('ui.cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING          => 'warning',
            self::COMPLETED        => 'success',
            self::WINTER_MAINTENANCE => 'primary',
            self::OPEN             => 'info',
            self::ASSIGNED         => 'warning',
            self::IN_PROGRESS      => 'primary',
            self::RESOLVED         => 'success',
            self::CLOSED           => 'gray',
            self::ON_HOLD          => 'danger',
            self::CANCELLED        => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PENDING          => 'heroicon-o-clock',
            self::COMPLETED        => 'heroicon-o-check-circle',
            self::WINTER_MAINTENANCE => 'heroicon-o-lifebuoy',
            self::OPEN             => 'heroicon-o-folder-open',
            self::ASSIGNED         => 'heroicon-o-user-circle',
            self::IN_PROGRESS      => 'heroicon-o-arrow-path',
            self::RESOLVED         => 'heroicon-o-check-badge',
            self::CLOSED           => 'heroicon-o-lock-closed',
            self::ON_HOLD          => 'heroicon-o-pause-circle',
            self::CANCELLED        => 'heroicon-o-x-circle',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::COMPLETED, self::CLOSED, self::CANCELLED]);
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
