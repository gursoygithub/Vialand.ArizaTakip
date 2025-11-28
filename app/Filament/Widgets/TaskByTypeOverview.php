<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TaskByTypeOverview extends BaseWidget
{
    protected static ?int $sort = 2;
    /**
     * @return string|null
     */
    public function getHeading(): ?string
    {
        return __('ui.tasks_by_unit');
    }
    protected function getStats(): array
    {
        $baseQuery = \App\Models\Task::query();

        // if not super_admin or dont view all tasks
        if (!auth()->user()->hasRole('super_admin') && !auth()->user()->can('view_all_tasks')) {
            $baseQuery->where('created_by', auth()->id());
        }

        if (class_exists(\App\Models\Unit::class)) {
            $units = \App\Models\Unit::all();
        } else {
            $units = \App\Models\Task::select('unit_id')->distinct()->pluck('unit_id')->map(fn($v) => (object)['id' => $v, 'name' => (string) $v]);
        }

        $stats = [];

        foreach ($units as $unit) {
            $unitId = $unit->id ?? $unit;
            $unitLabel = $unit->name ?? (string) $unitId;

            $q = (clone $baseQuery)->where('unit_id', $unitId);

            $total = $q->count();
            $pending = (clone $q)->where('status', TaskStatusEnum::PENDING)->count();
            $completed = (clone $q)->where('status', TaskStatusEnum::COMPLETED)->count();
            $winter = (clone $q)->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->count();

            $pending = 9999999;
            $completed = 9999999;
            $winter = 9999999;

            $totalLabel = __('ui.all');
            $pendingLabel = __('ui.task_by_unit_overview_waiting_label');
            $completedLabel = __('ui.task_by_unit_overview_completed_label');
            $winterLabel = __('ui.winter_maintenance');

//            $stats[] = Stat::make(
//                new \Illuminate\Support\HtmlString("<span style='font-size:1.3rem;font-weight:700'>{$unitLabel}</span>"),
//                new \Illuminate\Support\HtmlString("<span style='font-size:1.6rem;font-weight:800'>{$total}</span>")
//            )
//                ->icon('heroicon-o-wrench-screwdriver')
//                ->description(
//                    new \Illuminate\Support\HtmlString("
//                        <span style='font-size:1.05rem'>
//                            {$pendingLabel}: <span style='color:#F59E0B; font-weight:700'>{$pending}</span> •
//                            {$completedLabel}: <span style='color:#10B981; font-weight:700'>{$completed}</span> •
//                            {$winterLabel}: <span style='color:#3B82F6; font-weight:700'>{$winter}</span>
//                        </span>
//                    ")
//                );


            //$stats[] = Stat::make(new \Illuminate\Support\HtmlString("<span style=\"font-size:1.125rem;font-weight:600\">{$unitLabel}</span>"), new \Illuminate\Support\HtmlString("<span style=\"font-size:1.25rem;font-weight:700\">{$total}</span>"))
            $stats[] = Stat::make(new \Illuminate\Support\HtmlString("<span style=\"font-size:1.125rem;font-weight:600\">{$unitLabel}</span>"), new \Illuminate\Support\HtmlString("<span style=\"\"></span>"))
            //$stats[] = Stat::make(new \Illuminate\Support\HtmlString("<span style=\"\">{$unitLabel}</span>"), new \Illuminate\Support\HtmlString("<span style=\"\">{$total}</span>"))
                ->icon('heroicon-o-wrench-screwdriver')
                ->description(
                    new \Illuminate\Support\HtmlString("
                        <span style='font-size:1.05rem'>
                            <span style='font-weight:700; font-size: 1rem'>{$totalLabel}: </span>{$total} <br>
                            <span style='color:#F59E0B; font-weight:700; font-size: 1rem'>{$pendingLabel}:</span> {$pending} <br>
                            {$completedLabel}: <span style='color:#10B981; font-weight:700;  font-size: 1rem'>{$completed}</span> <br>
                            {$winterLabel}: <span style='color:#3B82F6; font-weight:700;  font-size: 1rem'>{$winter}</span>
                        </span>
                    ")
                );
            //->description(new \Illuminate\Support\HtmlString("{$pendingLabel}: <span style=\"color:#F59E0B; font-weight:600\">{$pending}</span> • Tamamlanan: <span style=\"color:#10B981; font-weight:600\">{$completed}</span> • Kış: <span style=\"color:#3B82F6; font-weight:600\">{$winter}</span>"));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_TaskByTypeOverview');
    }
}


//class TaskByTypeOverview extends BaseWidget
//{
//    protected function getStats(): array
//    {
//        $baseQuery = \App\Models\Task::query();
//
//        // if not super_admin or dont view all tasks
//        if (!auth()->user()->hasRole('super_admin') && !auth()->user()->can('view_all_tasks')) {
//            $baseQuery->where('created_by', auth()->id());
//        }
//
//        if (method_exists(\App\Enums\TaskTypeEnum::class, 'cases')) {
//            $types = \App\Enums\TaskTypeEnum::cases();
//        } else {
//            $types = \App\Models\Task::select('type_id')->distinct()->pluck('type_id')->map(fn($v) => (object)['value' => $v, 'name' => (string) $v]);
//        }
//
//        $stats = [];
//
//        foreach ($types as $type) {
//            $typeVal = $type->value ?? $type;
//            $typeLabel = $type->name ?? (string) $typeVal;
//
//            $q = (clone $baseQuery)->where('type_id', $typeVal);
//
//            $total = $q->count();
//            $pending = (clone $q)->where('status', TaskStatusEnum::PENDING)->count();
//            $completed = (clone $q)->where('status', TaskStatusEnum::COMPLETED)->count();
//            $winter = (clone $q)->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->count();
//
//            $stats[] = Stat::make($typeLabel, $total)
//                ->icon('heroicon-o-wrench-screwdriver')
//                ->description("Bekleyen: {$pending} • Tamamlanan: {$completed} • Kış: {$winter}")
//                ->descriptionColor('primary');
//        }
//
//        return $stats;
//    }
//}
