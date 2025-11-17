<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $tabs = [];

        $allQuery = Task::query();

        $tabs['all'] = Tab::make(__('ui.all'))
            ->badge($allQuery->count())
            ->modifyQueryUsing(function ($query) {
                return $query;
            });

        $tabs['pending'] = Tab::make(__('ui.pending'))
            ->badge((clone $allQuery)->where('status', TaskStatusEnum::PENDING)->count())
            ->badgeIcon('heroicon-o-clock')
            ->badgeColor('warning')
            ->modifyQueryUsing(function ($query) {
                return $query->where('status', TaskStatusEnum::PENDING);
            });

        $tabs['completed'] = Tab::make(__('ui.completed'))
            ->badge((clone $allQuery)->where('status', TaskStatusEnum::COMPLETED)->count())
            ->badgeIcon('heroicon-o-check-circle')
            ->badgeColor('success')
            ->modifyQueryUsing(function ($query) {
                return $query->where('status', TaskStatusEnum::COMPLETED);
            });

        $tabs['winter_maintenance'] = Tab::make(__('ui.winter_maintenance'))
            ->badge((clone $allQuery)->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->count())
            ->badgeIcon('heroicon-o-lifebuoy')
            ->badgeColor('info')
            ->modifyQueryUsing(function ($query) {
                return $query->where('status', TaskStatusEnum::WINTER_MAINTENANCE);
            });

        return $tabs;

    }
}
