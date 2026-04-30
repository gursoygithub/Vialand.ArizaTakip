<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TasksOverview extends BaseWidget
{
    public function getHeading(): ?string
    {
        return __('ui.task_by_status');
    }

    protected function getStats(): array
    {
        // Permission-aware visibility: own / group / all per Ticket::scopeVisibleBy
        $taskQuery = Task::query()->visibleBy(auth()->user());

        $allCount               = (clone $taskQuery)->count();
        $completedCount         = (clone $taskQuery)->where('status', TaskStatusEnum::COMPLETED)->count();
        $pendingCount           = (clone $taskQuery)->where('status', TaskStatusEnum::PENDING)->count();
        $winterMaintenanceCount = (clone $taskQuery)->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->count();

        return [
            Stat::make(__('ui.tasks'), $allCount)
                ->icon('heroicon-o-wrench-screwdriver')
                ->description(__('ui.all'))
                ->descriptionColor('primary'),

            Stat::make(__('ui.tasks'), $pendingCount)
                ->icon('heroicon-o-wrench-screwdriver')
                ->description(__('ui.pending'))
                ->descriptionIcon('heroicon-o-clock')
                ->descriptionColor('warning'),

            Stat::make(__('ui.tasks'), $winterMaintenanceCount)
                ->icon('heroicon-o-wrench-screwdriver')
                ->description(__('ui.winter_maintenance'))
                ->descriptionIcon('heroicon-o-lifebuoy')
                ->descriptionColor('info'),

            Stat::make(__('ui.tasks'), $completedCount)
                ->icon('heroicon-o-wrench-screwdriver')
                ->description(__('ui.completed'))
                ->descriptionIcon('heroicon-o-check-circle')
                ->descriptionColor('success'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_TasksOverview');
    }
}
