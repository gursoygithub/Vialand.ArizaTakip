<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AssignedPersonTypeEnum: int implements HasLabel, HasColor, HasIcon
{
    case EMPLOYEE      = 1;
    case SUBCONTRACTOR = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::EMPLOYEE      => __('ui.employee'),
            self::SUBCONTRACTOR => __('ui.subcontractor_company'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EMPLOYEE      => 'secondary',
            self::SUBCONTRACTOR => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::EMPLOYEE      => 'heroicon-o-user-group',
            self::SUBCONTRACTOR => 'heroicon-o-building-office-2',
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
