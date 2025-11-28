<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TasksOverview extends BaseWidget
{
    /**
     * @return string|null
     */
    public function getHeading(): ?string
    {
        return __('ui.tasks');
    }
    protected function getStats(): array
    {
        $taskQuery = \App\Models\Task::query();

        $allCount = $taskQuery->count();
        $completedCount = (clone $taskQuery)->where('status', TaskStatusEnum::COMPLETED)->count();
        $pendingCount = (clone $taskQuery)->where('status', TaskStatusEnum::PENDING)->count();
        $winterMaintenanceCount = (clone $taskQuery)->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->count();

        // if not super_admin or dont view all tasks
        if (!auth()->user()->hasRole('super_admin') && !auth()->user()->can('view_all_tasks')) {
            $taskQuery->where('created_by', auth()->id());
        }

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

            Stat::make(__('ui.tasks'), $completedCount)
                ->icon('heroicon-o-wrench-screwdriver')
                ->description(__('ui.completed'))
                ->descriptionIcon('heroicon-o-check-circle')
                ->descriptionColor('success'),

            Stat::make(__('ui.tasks'), $winterMaintenanceCount)
                ->icon('heroicon-o-wrench-screwdriver')
                ->description(__('ui.winter_maintenance'))
                ->descriptionIcon('heroicon-o-lifebuoy')
                ->descriptionColor('info')
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()->can('view_tasks_overview_widget');
    }
}
