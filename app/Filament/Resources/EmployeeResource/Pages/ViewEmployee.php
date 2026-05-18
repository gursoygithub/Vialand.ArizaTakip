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

        $closedOnTime     = (clone $performanceCohort)
            ->whereNotNull('resolved_at')
            ->where('sla_breached', false)
            ->count();
        $totalBreached    = (clone $performanceCohort)
            ->where('sla_breached', true)
            ->count();

        $denominator       = $closedOnTime + $totalBreached;
        $totalCohortCount  = $denominator; // sealed tickets only (on-time + breached)
        $complianceRate    = $denominator > 0
            ? round(($closedOnTime / $denominator) * 100, 1)
            : null;

        // Threshold captured for RepeatableEntry color closures.
        // Nullable: no data yet → closures return 'gray'.
        $threshold = $record->current_threshold;

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

        // Automated summary sentence — gives the manager an at-a-glance read
        // without requiring them to interpret all five raw numbers.
        $breached_active = $breachedCount;
        if ($breached_active > 0) {
            $summarySentence = "Aktif {$breached_active} SLA ihlali var — acil müdahale gerekiyor.";
        } elseif ($last30Total === 0) {
            $summarySentence = "Son 30 günde değerlendirilen talep yok. Genel oran %" . number_format($complianceRate ?? 0, 1, ',', '.') . ".";
        } elseif ($last30Rate !== null && $complianceRate !== null && $last30Rate >= $threshold && $complianceRate < $threshold) {
            $summarySentence = "Son 30 günde hedefi tutturdu (%" . number_format($last30Rate, 1, ',', '.') . "). Genel oran %" . number_format($complianceRate, 1, ',', '.') . " — eski ihlaller etkili.";
        } elseif ($last30Rate !== null && $last30Rate >= $threshold) {
            $summarySentence = "Performans hedef üzerinde. Son 30 gün: %" . number_format($last30Rate, 1, ',', '.') . ".";
        } elseif ($last30Rate !== null && $complianceRate !== null && $last30Rate < $complianceRate) {
            $summarySentence = "Son 30 günde performans düşüyor (%" . number_format($last30Rate, 1, ',', '.') . "). Geçmiş ortalama: %" . number_format($complianceRate, 1, ',', '.') . ".";
        } else {
            $summarySentence = "Son 30 gün: %" . number_format($last30Rate ?? 0, 1, ',', '.') . " · Tüm zamanlar: %" . number_format($complianceRate ?? 0, 1, ',', '.') . ".";
        }

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

                // 3. SLA Performans Analizi — sla_breached based, matches
                //    PerformanceService's compliance computation.
                //    §2 Aktif İş Yükü merged into Group A below.
                Section::make(__('ui.employee_sla_analysis'))
                    ->icon('heroicon-o-presentation-chart-line')
                    ->schema([
                        // Reopened warning banner — only shown when count > 0.
                        TextEntry::make('reopened_warning')
                            ->label('')
                            ->state("⚠ Bu personelin {$reopenedCount} ticket'ı yeniden açılmış")
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'text-amber-700 dark:text-amber-400 font-medium'])
                            ->visible(fn () => $reopenedCount > 0),

                        // Automated assessment sentence at top of §3.
                        TextEntry::make('summary_sentence')
                            ->label('')
                            ->state($summarySentence)
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'text-base font-medium italic text-gray-700 dark:text-gray-200']),

                        // Three thematic groups replacing the previous 5-flat-entry grid.
                        Grid::make(3)->schema([
                            // GROUP A — Şu Anki Durum (workload absorbed from deleted §2)
                            Section::make('Şu Anki Durum')
                                ->description('Personelin şu an üzerinde olan iş yükü')
                                ->compact()
                                ->columnSpan(1)
                                ->schema([
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
                                ]),

                            // GROUP B — Son 30 Gün Performansı
                            Section::make('Son 30 Gün Performansı')
                                ->description('Yakın dönem performans göstergesi')
                                ->compact()
                                ->columnSpan(1)
                                ->schema([
                                    TextEntry::make('last30_rate')
                                        ->label(__('ui.employee_last30_rate'))
                                        ->helperText('Sadece son 30 günde çözülen talepler')
                                        ->state($last30Rate === null ? '—' : '%' . number_format($last30Rate, 1, ',', '.'))
                                        ->badge()
                                        ->color(function () use ($last30Rate, $threshold) {
                                            if (is_null($threshold)) return 'gray';
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
                                ]),

                            // GROUP C — Tüm Zamanlar
                            Section::make('Tüm Zamanlar')
                                ->description('Geçmiş tüm dönemleri kapsar')
                                ->compact()
                                ->columnSpan(1)
                                ->schema([
                                    TextEntry::make('compliance_rate')
                                        ->label(__('ui.employee_general_success_rate'))
                                        ->helperText('Tüm zamanlar — kapalı ve aktif ihlaller dahil')
                                        ->state($complianceRate === null ? '—' : '%' . number_format($complianceRate, 1, ',', '.'))
                                        ->weight('bold')
                                        ->badge()
                                        ->color(function () use ($complianceRate, $threshold) {
                                            if (is_null($threshold)) return 'gray';
                                            if ($complianceRate === null) return 'gray';
                                            $hi = $threshold ?? 80;
                                            $lo = $threshold !== null ? $threshold * 0.75 : 50;
                                            return $complianceRate >= $hi
                                                ? 'success'
                                                : ($complianceRate >= $lo ? 'warning' : 'danger');
                                        }),

                                    TextEntry::make('current_threshold')
                                        ->label(__('ui.employee_sla_target'))
                                        ->state('%' . number_format($threshold, 1, ',', '.'))
                                        ->badge()
                                        ->color('info'),

                                    TextEntry::make('cohort_size')
                                        ->label(__('ui.employee_calc_base'))
                                        ->state($totalCohortCount . ' Talep')
                                        ->badge()
                                        ->color('info'),
                                ]),
                        ]),
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

                                        return [
                                            'unit_name'      => $stat->unit?->name ?? 'Tanımsız Birim',
                                            'stats'          => "{$onTime} / {$sealed}",
                                            'percentage'     => '%' . number_format($rate, 1, ',', '.'),
                                            'raw_percentage' => $rate,
                                            'threshold'      => $record->current_threshold,
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
                                    ->color(function ($state) use ($threshold) {
                                        if (is_null($threshold)) return 'gray';
                                        $rate = (float) str_replace(',', '.', ltrim((string) $state, '%'));
                                        return $rate >= $threshold ? 'success' : ($rate >= $threshold * 0.75 ? 'warning' : 'danger');
                                    }),
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
                                    ->map(function ($stat) use ($record) {
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
                                            'threshold'      => $record->current_threshold,
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
                                    ->color(function ($state) use ($threshold) {
                                        if (is_null($threshold)) return 'gray';
                                        $rate = (float) str_replace(',', '.', ltrim((string) $state, '%'));
                                        return $rate >= $threshold ? 'success' : ($rate >= $threshold * 0.75 ? 'warning' : 'danger');
                                    }),
                            ]),
                    ]),
            ]);
    }
}
