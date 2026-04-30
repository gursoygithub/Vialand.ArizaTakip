<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TaskResource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EmployeePerformanceOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    public function getHeading(): ?string
    {
        return __('ui.employee_performance_overview');
    }

    // Filament bu metodu bekler, Stat::make() nesnelerini burada döndürmeliyiz
    protected function getStats(): array
    {
        $data = $this->calculateSlaMetrics();

        return [
            Stat::make('Genel SLA Başarı Oranı', "% " . $data['slaRate'])
                // Artık 80 değil, veritabanındaki ortalama eşiğe göre kıyaslıyor
                ->description($data['slaRate'] >= $data['threshold'] ? 'Yüksek Performans' : 'Takip Gerekli')
                ->descriptionIcon($data['slaRate'] >= $data['threshold'] ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($data['slaRate'] >= $data['threshold'] ? 'success' : 'danger'),

            Stat::make('Aktif Bekleyen İşler', $data['activeCount'])
                ->description('Toplam İş Yükü')
                ->color('info')
                ->icon('heroicon-m-bolt'),

            Stat::make('Kritik İşler (Geciken)', $data['criticalCount'])
                ->description('SLA Süresi Dolan İşler')
                ->color($data['criticalCount'] > 0 ? 'danger' : 'gray')
                ->icon('heroicon-m-fire'),
        ];
    }

    // Hesaplama mantığını buraya aldık ki kod okunabilir olsun
    protected function calculateSlaMetrics(): array
    {
        $user = auth()->user();
        $isAdmin        = $user->hasRole('super_admin') || $user->can('view_all_employees');
        $canViewAllTasks = $user->can('view_all_tasks');

        $cacheKey = $isAdmin
            ? 'dashboard_sla_metrics_v4'
            : ($canViewAllTasks
                ? 'dashboard_sla_metrics_v4_all_tasks'
                : "dashboard_sla_metrics_v4_user_{$user->id}");

        return Cache::remember($cacheKey, 300, function () use ($user, $isAdmin, $canViewAllTasks) {
            $pendingStatuses = [
                \App\Enums\TaskStatusEnum::PENDING->value,
                \App\Enums\TaskStatusEnum::WINTER_MAINTENANCE->value
            ];

            $avgThreshold = DB::table('sla_policies')->avg('success_threshold') ?? 80;

            // table renamed: tasks → tickets
            $query = DB::table('tickets as t')->whereNull('t.deleted_at');

            // Katman 3: Ne admin ne de view_all_tasks → sadece kendi grubundaki çalışanlar
            if (!$isAdmin && !$canViewAllTasks) {
                $employeeId = $user->employee?->id;

                $memberEmployeeIds = DB::table('group_members')
                    ->join('groups', 'groups.id', '=', 'group_members.group_id')
                    ->where('groups.employee_id', $employeeId)
                    ->whereNull('group_members.deleted_at')
                    ->whereNull('groups.deleted_at')
                    ->pluck('group_members.employee_id')
                    ->push($employeeId)
                    ->filter()
                    ->unique();

                $query->whereIn('t.employee_id', $memberEmployeeIds);
            }

            // Katman 1 (admin) ve Katman 2 (view_all_tasks) → filtre yok, tüm görevler

            $stats = $query->selectRaw("
            COUNT(DISTINCT CASE WHEN t.sla_outcome IS NOT NULL THEN t.id END) as rated_tasks,
            COUNT(DISTINCT CASE WHEN t.sla_outcome = 'SUCCESS' THEN t.id END) as success_count,
            COUNT(DISTINCT CASE WHEN t.status IN (" . implode(',', $pendingStatuses) . ") THEN t.id END) as active_count,
            COUNT(DISTINCT CASE 
                WHEN t.status IN (" . implode(',', $pendingStatuses) . ") 
                AND (
                    t.sla_outcome = 'FAILED' OR 
                    t.sla_outcome = 'SLA_BREACHED' OR
                    (t.due_date IS NULL AND TIMESTAMPDIFF(MINUTE, t.created_at, NOW()) > (
                        SELECT deadline_minutes FROM sla_policies 
                        WHERE area_id = t.area_id AND priority = t.priority 
                        LIMIT 1
                    ))
                ) THEN t.id END) as critical_count
        ")->first();

            $ratedTasks   = $stats->rated_tasks ?? 0;
            $successCount = $stats->success_count ?? 0;

            return [
                'slaRate'       => $ratedTasks > 0 ? round(($successCount / $ratedTasks) * 100, 1) : 0,
                'activeCount'   => $stats->active_count ?? 0,
                'criticalCount' => $stats->critical_count ?? 0,
                'threshold'     => round($avgThreshold, 1),
            ];
        });
    }

//    protected function calculateSlaMetrics(): array
//    {
//        // Önbelleği v4 yaparak temiz bir başlangıç sağlıyoruz
//        return Cache::remember('dashboard_sla_metrics_v4', 300, function () {
//            $pendingStatuses = [
//                \App\Enums\TaskStatusEnum::PENDING->value,
//                \App\Enums\TaskStatusEnum::WINTER_MAINTENANCE->value
//            ];
//
//            // 1. Politikadaki "Başarı Eşiği Yüzdesi" ortalamasını dinamik alıyoruz
//            $avgThreshold = \Illuminate\Support\Facades\DB::table('sla_policies')->avg('success_threshold') ?? 80;
//
//            // 2. Canlı Görev Verilerini Çekiyoruz
//            $stats = \Illuminate\Support\Facades\DB::table('tasks as t')
//                ->selectRaw("
//                COUNT(DISTINCT CASE WHEN t.sla_outcome IS NOT NULL THEN t.id END) as rated_tasks,
//                COUNT(DISTINCT CASE WHEN t.sla_outcome = 'SUCCESS' THEN t.id END) as success_count,
//                COUNT(DISTINCT CASE WHEN t.status IN (" . implode(',', $pendingStatuses) . ") THEN t.id END) as active_count,
//                COUNT(DISTINCT CASE
//                    WHEN t.status IN (" . implode(',', $pendingStatuses) . ")
//                    AND (
//                        t.sla_outcome = 'FAILED' OR
//                        t.sla_outcome = 'SLA_BREACHED' OR
//                        -- Canlı Kontrol: Politika süresi (deadline_minutes) dolmuş işler
//                        (t.due_date IS NULL AND TIMESTAMPDIFF(MINUTE, t.created_at, NOW()) > (
//                            SELECT deadline_minutes FROM sla_policies
//                            WHERE area_id = t.area_id AND priority = t.priority
//                            LIMIT 1
//                        ))
//                    ) THEN t.id END) as critical_count
//            ")
//                ->whereNull('t.deleted_at')
//                ->first();
//
//            $ratedTasks = $stats->rated_tasks ?? 0;
//            $successCount = $stats->success_count ?? 0;
//
//            return [
//                'slaRate' => $ratedTasks > 0 ? round(($successCount / $ratedTasks) * 100, 1) : 0,
//                'activeCount' => $stats->active_count ?? 0,
//                'criticalCount' => $stats->critical_count ?? 0,
//                'threshold' => round($avgThreshold, 1), // Statların kıyaslanacağı dinamik baraj
//            ];
//        });
//    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_EmployeePerformanceOverview');
    }
}