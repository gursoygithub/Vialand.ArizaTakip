<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\EmployeeResource;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use Filament\Infolists;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        $record = $this->getRecord();

        // Pre-compute everything that the infolist sections will display.
        // Done here rather than inside getStateUsing closures so each metric
        // is one query, not one-per-render-pass.
        $activeStatuses   = [TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::ON_HOLD];
        $terminalStatuses = [TaskStatusEnum::RESOLVED, TaskStatusEnum::CLOSED, TaskStatusEnum::CANCELLED];

        $activeCount   = $record->tickets()->whereIn('status', $activeStatuses)->count();
        $breachedCount = $record->tickets()
            ->where('sla_breached', true)
            ->whereNotIn('status', $terminalStatuses)
            ->count();

        // Reopens against this employee's tickets — counted from the
        // ticket_status_histories audit log (terminal → ASSIGNED rows).
        $reopenedCount = TicketStatusHistory::query()
            ->whereIn('from_status', [TaskStatusEnum::RESOLVED->value, TaskStatusEnum::CLOSED->value])
            ->where('to_status', TaskStatusEnum::ASSIGNED->value)
            ->whereIn('ticket_id', Ticket::where('employee_id', $record->id)->select('id'))
            ->count();

        // SLA Performans Analizi — uses sla_breached, not the legacy
        // sla_outcome column. Aligned with PerformanceService.
        $performanceCohort = $record->tickets()
            ->whereNotIn('status', [TaskStatusEnum::CANCELLED]);

        $totalCohortCount = (clone $performanceCohort)->count();
        $closedOnTime     = (clone $performanceCohort)
            ->whereNotNull('resolved_at')
            ->where('sla_breached', false)
            ->count();
        $totalBreached    = (clone $performanceCohort)
            ->where('sla_breached', true)
            ->count();

        $denominator       = $closedOnTime + $totalBreached;
        $complianceRate    = $denominator > 0
            ? round(($closedOnTime / $denominator) * 100, 1)
            : null;
        $threshold         = $record->current_threshold ? (float) $record->current_threshold : null;

        // Son 30 gün — matches PerformanceService::aggregate() denominator
        // (all resolved, CANCELLED excluded). Anchored on resolved_at so the
        // window is "what was closed in the last 30 days", not "created_at".
        $thirtyDaysAgo = now()->subDays(30);
        $last30Total   = $record->tickets()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $thirtyDaysAgo)
            ->whereNotIn('status', [TaskStatusEnum::CANCELLED])
            ->count();
        $last30OnTime  = $record->tickets()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $thirtyDaysAgo)
            ->whereNotIn('status', [TaskStatusEnum::CANCELLED])
            ->where('sla_breached', false)
            ->count();
        $last30Rate    = $last30Total > 0
            ? round($last30OnTime / $last30Total * 100, 1)
            : null;

        return $infolist
            ->schema([
                // 1. Personel kimliği
                Section::make(__('ui.employee'))
                    ->icon('heroicon-o-user-circle')
                    ->columns(3)
                    ->compact()
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('ui.name'))
                            ->helperText(fn ($record): ?string => $record->title ?? '-')
                            ->icon('heroicon-o-user')
                            ->weight('bold'),

                        TextEntry::make('email')
                            ->label(__('ui.email'))
                            ->icon('heroicon-o-envelope'),

                        TextEntry::make('phone')
                            ->label(__('ui.phone'))
                            ->icon('heroicon-o-phone'),
                    ]),

                // 2. Aktif iş yükü — what's on the tech's plate right now.
                Section::make(__('ui.employee_active_workload'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('active_tickets')
                                ->label(__('ui.employee_in_progress'))
                                ->badge()
                                ->color($activeCount > 0 ? 'warning' : 'gray')
                                ->state($activeCount),

                            TextEntry::make('breached_active')
                                ->label(__('ui.employee_sla_breach'))
                                ->badge()
                                ->color($breachedCount > 0 ? 'danger' : 'gray')
                                ->state($breachedCount),

                            TextEntry::make('reopened_count')
                                ->label(__('ui.employee_reopened'))
                                ->badge()
                                ->color($reopenedCount > 0 ? 'warning' : 'gray')
                                ->state($reopenedCount),
                        ]),
                    ]),

                // 3. SLA Performans Analizi — sla_breached based, matches
                //    PerformanceService's compliance computation.
                Section::make(__('ui.employee_sla_analysis'))
                    ->icon('heroicon-o-presentation-chart-line')
                    ->columns(6)
                    ->schema([
                        TextEntry::make('compliance_rate')
                            ->label(__('ui.employee_general_success_rate'))
                            ->helperText('Tüm zamanlar — kapalı ve aktif ihlaller dahil')
                            ->state($complianceRate === null ? '—' : '%' . number_format($complianceRate, 1, ',', '.'))
                            ->weight('bold')
                            ->badge()
                            ->color(function () use ($complianceRate, $threshold) {
                                if ($complianceRate === null) return 'gray';
                                $hi = $threshold ?? 80;
                                $lo = $threshold !== null ? $threshold * 0.75 : 50;
                                return $complianceRate >= $hi
                                    ? 'success'
                                    : ($complianceRate >= $lo ? 'warning' : 'danger');
                            }),

                        TextEntry::make('last30_rate')
                            ->label(__('ui.employee_last30_rate'))
                            ->helperText('Sadece son 30 günde çözülen talepler')
                            ->state($last30Rate === null ? '—' : '%' . number_format($last30Rate, 1, ',', '.'))
                            ->badge()
                            ->color(function () use ($last30Rate, $threshold) {
                                if ($last30Rate === null) return 'gray';
                                $hi = $threshold ?? 80;
                                $lo = $threshold !== null ? $threshold * 0.75 : 50;
                                return $last30Rate >= $hi ? 'success' : ($last30Rate >= $lo ? 'warning' : 'danger');
                            }),

                        TextEntry::make('last30_tickets')
                            ->label(__('ui.employee_last30_tickets'))
                            ->state((string) $last30Total)
                            ->badge()
                            ->color('gray'),

                        TextEntry::make('current_threshold')
                            ->label(__('ui.employee_sla_target'))
                            ->state($threshold === null ? '—' : '%' . number_format($threshold, 1, ',', '.'))
                            ->badge()
                            ->color('info'),

                        TextEntry::make('cohort_size')
                            ->label(__('ui.employee_calc_base'))
                            ->state($totalCohortCount . ' Talep')
                            ->badge()
                            ->color('info'),
                    ]),

                // 4. Birim Bazlı SLA Dağılımı — backed by sla_breached.
                //    "Sealed" cohort = closed_on_time + breached, matching
                //    section 3's denominator. Cancelled tickets excluded;
                //    units with no sealed tickets are dropped via HAVING.
                Section::make(__('ui.employee_unit_sla'))
                    ->description('Personelin hangi birimde ne kadar başarılı olduğunun dökümü.')
                    ->icon('heroicon-o-rectangle-group')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('unit_performance')
                            ->label('')
                            ->getStateUsing(function ($record) {
                                return $record->tickets()
                                    ->whereNotIn('status', [TaskStatusEnum::CANCELLED->value])
                                    ->selectRaw('
                                        unit_id,
                                        SUM(CASE WHEN resolved_at IS NOT NULL AND sla_breached = 0 THEN 1 ELSE 0 END) as on_time_count,
                                        SUM(CASE WHEN sla_breached = 1 THEN 1 ELSE 0 END) as breached_count
                                    ')
                                    ->groupBy('unit_id')
                                    ->havingRaw('(on_time_count + breached_count) > 0')
                                    ->with('unit')
                                    ->get()
                                    ->map(function ($stat) use ($record) {
                                        $onTime = (int) $stat->on_time_count;
                                        $sealed = $onTime + (int) $stat->breached_count;
                                        $rate   = (float) ($sealed > 0
                                            ? round($onTime / $sealed * 100, 1)
                                            : 0);

                                        $threshold = (float) ($record->current_threshold ?? 80);
                                        $hi        = $threshold;
                                        $lo        = $threshold * 0.75;
                                        $color     = $rate >= $hi ? 'success' : ($rate >= $lo ? 'warning' : 'danger');

                                        return [
                                            'unit_name'      => $stat->unit?->name ?? 'Tanımsız Birim',
                                            'stats'          => "{$onTime} / {$sealed}",
                                            'percentage'     => '%' . number_format($rate, 1, ',', '.'),
                                            'raw_percentage' => $rate,
                                            'color'          => $color,
                                        ];
                                    });
                            })
                            ->columns(3)
                            ->grid(2)
                            ->schema([
                                TextEntry::make('unit_name')
                                    ->label(__('ui.employee_col_unit'))
                                    ->weight('bold'),

                                TextEntry::make('stats')
                                    ->label(__('ui.employee_on_time_sealed'))
                                    ->color('gray'),

                                TextEntry::make('percentage')
                                    ->label(__('ui.employee_col_rate'))
                                    ->badge()
                                    ->color(fn ($state, $record) => $record['color'] ?? 'gray'),
                            ]),
                    ]),

                // 5. Öncelik Bazlı SLA Dağılımı — same sla_breached basis
                //    as section 4, grouped by priority.
                Section::make(__('ui.employee_priority_sla'))
                    ->description('Personelin görev önceliklerine göre performans dökümü.')
                    ->icon('heroicon-o-funnel')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('priority_performance')
                            ->label('')
                            ->getStateUsing(function ($record) {
                                return $record->tickets()
                                    ->whereNotIn('status', [TaskStatusEnum::CANCELLED->value])
                                    ->selectRaw('
                                        priority,
                                        SUM(CASE WHEN resolved_at IS NOT NULL AND sla_breached = 0 THEN 1 ELSE 0 END) as on_time_count,
                                        SUM(CASE WHEN sla_breached = 1 THEN 1 ELSE 0 END) as breached_count
                                    ')
                                    ->groupBy('priority')
                                    ->havingRaw('(on_time_count + breached_count) > 0')
                                    ->get()
                                    ->map(function ($stat) {
                                        $priorityEnum = $stat->priority instanceof \App\Enums\TaskPriorityEnum
                                            ? $stat->priority
                                            : \App\Enums\TaskPriorityEnum::tryFrom($stat->priority);

                                        $onTime = (int) $stat->on_time_count;
                                        $sealed = $onTime + (int) $stat->breached_count;
                                        $rate   = (float) ($sealed > 0
                                            ? round($onTime / $sealed * 100, 1)
                                            : 0);

                                        return [
                                            'priority_label' => $priorityEnum?->getLabel() ?? 'Bilinmiyor',
                                            'priority_color' => $priorityEnum?->getColor() ?? 'gray',
                                            'stats'          => "{$onTime} / {$sealed}",
                                            'percentage'     => '%' . number_format($rate, 1, ',', '.'),
                                            'raw_percentage' => $rate,
                                        ];
                                    });
                            })
                            ->columns(3)
                            ->grid(2)
                            ->schema([
                                TextEntry::make('priority_label')
                                    ->label(__('ui.employee_col_priority'))
                                    ->weight('bold')
                                    ->badge()
                                    ->color(fn ($record) => $record['priority_color']),

                                TextEntry::make('stats')
                                    ->label(__('ui.employee_on_time_sealed'))
                                    ->icon('heroicon-m-clipboard-document-check')
                                    ->color('gray'),

                                TextEntry::make('percentage')
                                    ->label(__('ui.employee_col_success_rate'))
                                    ->badge()
                                    ->color(function ($state, $record) use ($threshold) {
                                        $rate = (float) ($record['raw_percentage'] ?? 0);
                                        $hi   = (float) ($threshold ?? 80);
                                        $lo   = $threshold !== null ? (float) $threshold * 0.75 : 50.0;
                                        return $rate >= $hi ? 'success' : ($rate >= $lo ? 'warning' : 'danger');
                                    }),
                            ]),
                    ]),
            ]);
    }
}
