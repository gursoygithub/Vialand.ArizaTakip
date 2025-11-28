<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TaskByTypeOverview extends BaseWidget
{
    protected static ?int $sort = 2;

    public function getHeading(): ?string
    {
        return __('ui.tasks_by_unit');
    }

    protected function getStats(): array
    {
        $baseQuery = \App\Models\Task::query();

        if (!auth()->user()->hasRole('super_admin') && !auth()->user()->can('view_all_tasks')) {
            $baseQuery->where('created_by', auth()->id());
        }

        $units = class_exists(\App\Models\Unit::class)
            ? \App\Models\Unit::all()
            : \App\Models\Task::select('unit_id')->distinct()->pluck('unit_id')->map(fn($v) => (object)[
                'id' => $v,
                'name' => (string)$v,
            ]);

        $stats = [];

        foreach ($units as $unit) {
            $unitId = $unit->id ?? $unit;
            $unitLabel = $unit->name ?? (string)$unitId;

            $q = (clone $baseQuery)->where('unit_id', $unitId);

            $total     = $q->count();
            $pending   = (clone $q)->where('status', TaskStatusEnum::PENDING)->count();
            $completed = (clone $q)->where('status', TaskStatusEnum::COMPLETED)->count();
            $winter    = (clone $q)->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->count();

            $totalLabel = __('ui.all');
            $pendingLabel = __('ui.task_by_unit_overview_waiting_label');
            $completedLabel = __('ui.task_by_unit_overview_completed_label');
            $winterLabel = __('ui.winter_maintenance');

            // SVG ikonlar: stroke/fill currentColor -> badge text color uygular
            $iconTotal = '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11H9v4l3 1 .0-1.2L11 7z"/></svg>';
            $iconPending = '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2 2"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 19.5A7.5 7.5 0 1112 4.5a7.5 7.5 0 010 15z"/></svg>';
            $iconCompleted = '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
            $iconWinter = '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M5 8l14 0M5 16l14 0M8 6l8 12M16 6l-8 12"/></svg>';

            // Badge markup — renk sınıfları badge container üzerinde (ikon + sayı aynı renk alır)
            $html = "
                <div class='space-y-2 mt-2 text-sm'>
                    <div class='flex items-center justify-between'>
                        <div class='text-amber-600 font-medium'>{$pendingLabel}</div>
                        <div class='inline-flex items-center gap-2'>
                            <span class='inline-flex items-center gap-2 px-2 py-0.5 rounded-md bg-amber-100 text-amber-600 font-semibold'>
                                {$iconPending}
                                <span>{$pending}</span>
                            </span>
                        </div>
                    </div>

                    <div class='flex items-center justify-between'>
                        <div class='text-emerald-600 font-medium'>{$completedLabel}</div>
                        <div class='inline-flex items-center gap-2'>
                            <span class='inline-flex items-center gap-2 px-2 py-0.5 rounded-md bg-emerald-100 text-emerald-600 font-semibold'>
                                {$iconCompleted}
                                <span>{$completed}</span>
                            </span>
                        </div>
                    </div>

                    <div class='flex items-center justify-between'>
                        <div class='text-blue-600 font-medium'>{$winterLabel}</div>
                        <div class='inline-flex items-center gap-2'>
                            <span class='inline-flex items-center gap-2 px-2 py-0.5 rounded-md bg-blue-100 text-blue-600 font-semibold'>
                                {$iconWinter}
                                <span>{$winter}</span>
                            </span>
                        </div>
                    </div>
                </div>
            ";

            $stats[] = Stat::make($unitLabel, $total)
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('primary')
                ->extraAttributes(['class' => 'p-4'])
                ->description(new \Illuminate\Support\HtmlString($html));
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
//    protected static ?int $sort = 2;
//    /**
//     * @return string|null
//     */
//    public function getHeading(): ?string
//    {
//        return __('ui.tasks_by_unit');
//    }
//    protected function getStats(): array
//    {
//        $baseQuery = \App\Models\Task::query();
//
//        // if not super_admin or dont view all tasks
//        if (!auth()->user()->hasRole('super_admin') && !auth()->user()->can('view_all_tasks')) {
//            $baseQuery->where('created_by', auth()->id());
//        }
//
//        if (class_exists(\App\Models\Unit::class)) {
//            $units = \App\Models\Unit::all();
//        } else {
//            $units = \App\Models\Task::select('unit_id')->distinct()->pluck('unit_id')->map(fn($v) => (object)['id' => $v, 'name' => (string) $v]);
//        }
//
//        $stats = [];
//
//        foreach ($units as $unit) {
//            $unitId = $unit->id ?? $unit;
//            $unitLabel = $unit->name ?? (string) $unitId;
//
//            $q = (clone $baseQuery)->where('unit_id', $unitId);
//
//            $total = $q->count();
//            $pending = (clone $q)->where('status', TaskStatusEnum::PENDING)->count();
//            $completed = (clone $q)->where('status', TaskStatusEnum::COMPLETED)->count();
//            $winter = (clone $q)->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->count();
//
//            $pending = 9999999;
//            $completed = 9999999;
//            $winter = 9999999;
//
//            $totalLabel = __('ui.all');
//            $pendingLabel = __('ui.task_by_unit_overview_waiting_label');
//            $completedLabel = __('ui.task_by_unit_overview_completed_label');
//            $winterLabel = __('ui.winter_maintenance');
//
////            $stats[] = Stat::make(
////                new \Illuminate\Support\HtmlString("<span style='font-size:1.3rem;font-weight:700'>{$unitLabel}</span>"),
////                new \Illuminate\Support\HtmlString("<span style='font-size:1.6rem;font-weight:800'>{$total}</span>")
////            )
////                ->icon('heroicon-o-wrench-screwdriver')
////                ->description(
////                    new \Illuminate\Support\HtmlString("
////                        <span style='font-size:1.05rem'>
////                            {$pendingLabel}: <span style='color:#F59E0B; font-weight:700'>{$pending}</span> •
////                            {$completedLabel}: <span style='color:#10B981; font-weight:700'>{$completed}</span> •
////                            {$winterLabel}: <span style='color:#3B82F6; font-weight:700'>{$winter}</span>
////                        </span>
////                    ")
////                );
//
//
//            //$stats[] = Stat::make(new \Illuminate\Support\HtmlString("<span style=\"font-size:1.125rem;font-weight:600\">{$unitLabel}</span>"), new \Illuminate\Support\HtmlString("<span style=\"font-size:1.25rem;font-weight:700\">{$total}</span>"))
//            $stats[] = Stat::make(new \Illuminate\Support\HtmlString("<span style=\"font-size:1.125rem;font-weight:600\">{$unitLabel}</span>"), new \Illuminate\Support\HtmlString("<span style=\"\"></span>"))
//            //$stats[] = Stat::make(new \Illuminate\Support\HtmlString("<span style=\"\">{$unitLabel}</span>"), new \Illuminate\Support\HtmlString("<span style=\"\">{$total}</span>"))
//                ->icon('heroicon-o-wrench-screwdriver')
//                ->description(
//                    new \Illuminate\Support\HtmlString("
//                        <span style='font-size:1.05rem; font-weight:700'>
//                            <span style='font-weight:700; font-size:1rem'>{$totalLabel}:</span> <span style='font-weight:700'>{$total}</span> <br>
//                            <span style='color:#F59E0B; font-weight:700; font-size:1rem'>{$pendingLabel}:</span> <span style='font-weight:700'>{$pending}</span> <br>
//                            <span style='color:#10B981; font-weight:700; font-size:1rem'>{$completedLabel}:</span> <span style='font-weight:700'>{$completed}</span> <br>
//                            <span style='color:#3B82F6; font-weight:700; font-size:1rem'>{$winterLabel}:</span> <span style='font-weight:700'>{$winter}</span>
//                        </span>
//                    ")
//                );
//            //->description(new \Illuminate\Support\HtmlString("{$pendingLabel}: <span style=\"color:#F59E0B; font-weight:600\">{$pending}</span> • Tamamlanan: <span style=\"color:#10B981; font-weight:600\">{$completed}</span> • Kış: <span style=\"color:#3B82F6; font-weight:600\">{$winter}</span>"));
//        }
//
//        return $stats;
//    }
//
//    public static function canView(): bool
//    {
//        return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_TaskByTypeOverview');
//    }
//}


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
