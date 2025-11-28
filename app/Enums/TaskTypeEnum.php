<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TaskTypeEnum: int implements HasLabel, HasIcon, HasColor
{
    case OPERATION = 1;
    case BOX_OFFICE = 2;
    case GUEST_RELATIONS = 3;
    case STORES = 4;
    case FAIR_SERVICES = 5;
    public function getLabel(): string
    {
        return match ($this) {
            self::OPERATION => __('ui.operation'),
            self::BOX_OFFICE => __('ui.box_office'),
            self::GUEST_RELATIONS => __('ui.guest_relations'),
            self::STORES => __('ui.stores'),
            self::FAIR_SERVICES => __('ui.fair_services'),
        };
    }

    public function getColor(): string
    {
        return 'info';
//        return match ($this) {
//            self::OPERATION => 'success',
//            self::BOX_OFFICE => 'warning',
//            self::GUEST_RELATIONS => 'info',
//            self::STORES => 'primary',
//            self::FAIR_SERVICES => 'secondary',
//        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::OPERATION => 'heroicon-o-cog',
            self::BOX_OFFICE => 'heroicon-o-archive-box',
            self::GUEST_RELATIONS => 'heroicon-o-user-group',
            self::STORES => 'heroicon-o-shopping-bag',
            self::FAIR_SERVICES => 'heroicon-o-briefcase',
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
