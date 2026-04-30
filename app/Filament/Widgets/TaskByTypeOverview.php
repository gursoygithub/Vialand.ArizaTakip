<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use App\Models\Task;
use App\Models\Unit;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\DB;

class TaskByTypeOverview extends BaseWidget
{
    protected static ?int $sort = 2;

    // Widget'ın her 10 saniyede bir çalışıp sistemi yormasını engellemek için polling'i kapatalım veya uzatalım
    protected static ?string $pollingInterval = '60s';

    public function getHeading(): ?string
    {
        return __('ui.tasks_by_unit');
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $hasPermission = $user->hasRole('super_admin') || $user->can('view_all_tasks');

        // 1. ADIM: Tüm veriyi TEK SEFERDE çekiyoruz (Gruplayarak)
        // Bu sayede foreach içinde defalarca sorgu atmıyoruz.
        $taskData = Task::query()
            ->select('unit_id', 'status', DB::raw('count(*) as count'))
            ->when(!$hasPermission, function ($query) use ($user) {
                $query->where(function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                        ->orWhere('employee_id', function ($sub) use ($user) {
                            $sub->select('id')->from('employees')->where('email', $user->email);
                        });
                });
            })
            ->groupBy('unit_id', 'status')
            ->get()
            ->groupBy('unit_id'); // PHP tarafında birimlere göre ayırıyoruz

        // 2. ADIM: Birimleri alıyoruz
        $units = Unit::all();
        $stats = [];

        foreach ($units as $unit) {
            // Bu birime ait verileri yukarıdaki toplu paketten alıyoruz
            $unitGroup = $taskData->get($unit->id, collect());

            $total     = $unitGroup->sum('count');
            $pending   = $unitGroup->where('status', TaskStatusEnum::PENDING)->sum('count');
            $completed = $unitGroup->where('status', TaskStatusEnum::COMPLETED)->sum('count');
            $winter    = $unitGroup->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->sum('count');

            // Etiketler
            $pendingLabel = __('ui.task_by_unit_overview_waiting_label');
            $completedLabel = __('ui.task_by_unit_overview_completed_label');
            $winterLabel = __('ui.winter_maintenance');

            // HTML Markup (Dokunmadım, senin tasarımın aynen duruyor)
            $html = "
            <div class='space-y-2 mt-2 text-sm'>
                <div class='flex items-center justify-between'>
                    <div class='text-amber-600 font-medium'>{$pendingLabel}</div>
                    <div class='inline-flex items-center gap-2'>
                        <span class='inline-flex items-center gap-2 px-2 py-0.5 rounded-md bg-gray-100 text-amber-600 font-semibold ml-2'>
                            <svg xmlns='http://www.w3.org/2000/svg' class='w-4 h-4' fill='none' viewBox='0 0 24 24' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'/><path d='M12 6v6l4 2'/></svg>
                            <span>{$pending}</span>
                        </span>
                    </div>
                </div>
                <div class='flex items-center justify-between'>
                    <div class='text-emerald-600 font-medium'>{$completedLabel}</div>
                    <div class='inline-flex items-center gap-2'>
                        <span class='inline-flex items-center gap-2 px-2 py-0.5 rounded-md bg-gray-100 text-emerald-600 font-semibold ml-2'>
                            <svg xmlns='http://www.w3.org/2000/svg' class='w-4 h-4' fill='none' viewBox='0 0 24 24' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M5 13l4 4L19 7'/></svg>
                            <span>{$completed}</span>
                        </span>
                    </div>
                </div>
                <div class='flex items-center justify-between'>
                    <div class='text-blue-600 font-medium'>{$winterLabel}</div>
                    <div class='inline-flex items-center gap-2'>
                        <span class='inline-flex items-center gap-2 px-2 py-0.5 rounded-md bg-gray-100 text-blue-600 font-semibold ml-2'>
                            <svg xmlns='http://www.w3.org/2000/svg' class='w-4 h-4' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'/><circle cx='12' cy='12' r='4'/><line x1='4.93' y1='4.93' x2='9.17' y2='9.17'/><line x1='14.83' y1='14.83' x2='19.07' y2='19.07'/><line x1='14.83' y1='9.17' x2='19.07' y2='4.93'/><line x1='4.93' y1='19.07' x2='9.17' y2='14.83'/></svg>
                            <span>{$winter}</span>
                        </span>
                    </div>
                </div>
            </div>";

            $stats[] = Stat::make($unit->name, $total)
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('primary')
                ->extraAttributes(['class' => 'p-4'])
                ->description(new HtmlString($html));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_TaskByTypeOverview');
    }
}