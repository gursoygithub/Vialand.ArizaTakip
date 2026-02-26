<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use App\Filament\Resources\TaskResource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EmployeePerformanceOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $stats = Cache::remember('dashboard_stats_overview', 3600, function () {
            return DB::table('tasks')
                ->selectRaw("
                    COUNT(CASE WHEN due_date IS NOT NULL THEN 1 END) as total_completed,
                    COUNT(CASE WHEN sla_outcome = 'SUCCESS' THEN 1 END) as on_time_count,
                    COUNT(CASE WHEN due_date IS NULL THEN 1 END) as active_count,
                    COUNT(CASE WHEN sla_outcome = 'SLA_BREACHED' OR (due_date IS NULL AND sla_outcome = 'FAILED') THEN 1 END) as critical_count
                ")
                ->whereNull('deleted_at')
                ->first();
        });

        $totalCompleted = $stats->total_completed ?? 0;
        $onTimeCount = $stats->on_time_count ?? 0;
        $slaRate = $totalCompleted > 0 ? round(($onTimeCount / $totalCompleted) * 100, 1) : 0;

        return [
            Stat::make('Genel SLA Başarı Oranı', "% $slaRate")
                ->description($slaRate >= 80 ? 'Yüksek Performans' : 'Takip Gerekli')
                ->descriptionIcon($slaRate >= 80 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($slaRate >= 80 ? 'success' : 'danger')
                ->url(TaskResource::getUrl('index')),

            Stat::make('Aktif Bekleyen İşler', $stats->active_count ?? 0)
                ->color('info')
                ->icon('heroicon-m-bolt'),

            Stat::make('Kritik İşler', $stats->critical_count ?? 0)
                ->description('Gecikmiş veya Riskli')
                ->color($stats->critical_count > 0 ? 'danger' : 'success')
                ->icon('heroicon-m-fire'),
        ];
    }
}