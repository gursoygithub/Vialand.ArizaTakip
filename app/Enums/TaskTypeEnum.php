<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TaskTypeEnum: int implements HasLabel, HasIcon, HasColor
{
    case OPERATION = 1;
    case BOX_OFFICE_AND_GUEST_RELATIONS = 2;
    case STORES_AND_FAIR_SERVICES = 3;
    public function getLabel(): string
    {
        return match ($this) {
            self::OPERATION => __('ui.operation'),
            self::BOX_OFFICE_AND_GUEST_RELATIONS => __('ui.box_office_and_guest_relations'),
            self::STORES_AND_FAIR_SERVICES => __('ui.stores_and_fair_services'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OPERATION => 'success',
            self::BOX_OFFICE_AND_GUEST_RELATIONS => 'warning',
            self::STORES_AND_FAIR_SERVICES => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::OPERATION => 'heroicon-o-cog',
            self::BOX_OFFICE_AND_GUEST_RELATIONS => 'heroicon-o-ticket',
            self::STORES_AND_FAIR_SERVICES => 'heroicon-o-shopping-bag',
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
