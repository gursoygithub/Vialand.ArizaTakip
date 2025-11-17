<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\ManagerStatusEnum;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $tabs = [];
        $canViewAllUsers = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_users');

        // "All" tab
        $allQuery = User::query()->where('id', '>', 1);
        if (!$canViewAllUsers) {
            $allQuery->where('created_by', auth()->id());
        }

        $tabs['all'] = Tab::make(__('ui.all'))
            ->badge($allQuery->count())
            ->modifyQueryUsing(function ($query) use ($canViewAllUsers) {
                $query->where('id', '>', 1);
                if (!$canViewAllUsers) {
                    $query->where('created_by', auth()->id());
                }
                return $query;
            });

        // "Active" tab
        $activeQuery = User::query()->where('status', ManagerStatusEnum::ACTIVE)->where('id', '>', 1);
        if (!$canViewAllUsers) {
            $activeQuery->where('created_by', auth()->id());
        }

        $tabs['active'] = Tab::make(__('ui.active'))
            ->badge($activeQuery->count())
            ->badgeIcon('heroicon-o-check-circle')
            ->badgeColor('success')
            ->modifyQueryUsing(function ($query) use ($canViewAllUsers) {
                $query->where('status', ManagerStatusEnum::ACTIVE)->where('id', '>', 1);
                if (!$canViewAllUsers) {
                    $query->where('created_by', auth()->id());
                }
                return $query;
            });

        // "Inactive" tab
        $inactiveQuery = User::query()->where('status', ManagerStatusEnum::INACTIVE)->where('id', '>', 1);
        if (!$canViewAllUsers) {
            $inactiveQuery->where('created_by', auth()->id());
        }

        $tabs['inactive'] = Tab::make(__('ui.inactive'))
            ->badge($inactiveQuery->count())
            ->badgeIcon('heroicon-o-x-circle')
            ->badgeColor('danger')
            ->modifyQueryUsing(function ($query) use ($canViewAllUsers) {
                $query->where('status', ManagerStatusEnum::INACTIVE)->where('id', '>', 1);
                if (!$canViewAllUsers) {
                    $query->where('created_by', auth()->id());
                }
                return $query;
            });

        return $tabs;
    }
}
