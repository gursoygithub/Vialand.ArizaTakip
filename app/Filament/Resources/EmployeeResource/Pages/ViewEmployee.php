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
        $threshold         = $record->current_threshold;
        $isCompliant       = $complianceRate !== null && $threshold !== null
            ? $complianceRate >= $threshold
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
                Section::make('Aktif İş Yükü')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('active_tickets')
                                ->label('Aktif Ticket')
                                ->badge()
                                ->color($activeCount > 0 ? 'warning' : 'gray')
                                ->state($activeCount),

                            TextEntry::make('breached_active')
                                ->label('SLA İhlali')
                                ->badge()
                                ->color($breachedCount > 0 ? 'danger' : 'gray')
                                ->state($breachedCount),

                            TextEntry::make('reopened_count')
                                ->label('Yeniden Açılan')
                                ->badge()
                                ->color($reopenedCount > 0 ? 'warning' : 'gray')
                                ->state($reopenedCount),
                        ]),
                    ]),

                // 3. SLA Performans Analizi — sla_breached based, matches
                //    PerformanceService's compliance computation.
                Section::make('SLA Performans Analizi')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('compliance_rate')
                            ->label('SLA Başarı Oranı')
                            ->state($complianceRate === null ? '—' : '%' . number_format($complianceRate, 1, ',', '.'))
                            ->weight('bold')
                            ->color(fn () => match ($isCompliant) {
                                true  => 'success',
                                false => 'danger',
                                null  => 'gray',
                            }),

                        TextEntry::make('current_threshold')
                            ->label('SLA Hedef Oranı')
                            ->state($threshold === null ? '—' : '%' . number_format($threshold, 1, ',', '.')),

                        TextEntry::make('cohort_size')
                            ->label('İş Hacmi')
                            ->state($totalCohortCount . ' Talep')
                            ->color('info'),

                        TextEntry::make('compliance_status')
                            ->label('Genel Yeterlilik')
                            ->state(match ($isCompliant) {
                                true  => 'SLA UYUMLU',
                                false => 'GELİŞTİRİLMELİ',
                                null  => 'VERİ YOK',
                            })
                            ->badge()
                            ->color(fn () => match ($isCompliant) {
                                true  => 'success',
                                false => 'danger',
                                null  => 'gray',
                            }),
                    ]),

                // 4. Birim Bazlı SLA Dağılımı — backed by sla_breached.
                //    "Sealed" cohort = closed_on_time + breached, matching
                //    section 3's denominator. Cancelled tickets excluded;
                //    units with no sealed tickets are dropped via HAVING.
                Section::make('Birim Bazlı SLA Dağılımı')
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
                                    ->map(function ($stat) {
                                        $sealed = (int) $stat->on_time_count + (int) $stat->breached_count;
                                        $rate   = $sealed > 0
                                            ? round(((int) $stat->on_time_count / $sealed) * 100, 1)
                                            : 0;

                                        return [
                                            'unit_name'      => $stat->unit?->name ?? 'Tanımsız Birim',
                                            'stats'          => "{$stat->on_time_count} / {$sealed}",
                                            'percentage'     => '%' . number_format($rate, 1, ',', '.'),
                                            'raw_percentage' => $rate,
                                        ];
                                    });
                            })
                            ->columns(3)
                            ->grid(2)
                            ->schema([
                                TextEntry::make('unit_name')
                                    ->label('Birim')
                                    ->weight('bold'),

                                TextEntry::make('stats')
                                    ->label('Zamanında / Sealed')
                                    ->color('gray'),

                                TextEntry::make('percentage')
                                    ->label('Oran')
                                    ->badge()
                                    ->color(fn ($state, $record) =>
                                        ($record['raw_percentage'] ?? 0) >= 80 ? 'success' : (($record['raw_percentage'] ?? 0) >= 50 ? 'warning' : 'danger')
                                    ),
                            ]),
                    ]),

                // 5. Öncelik Bazlı SLA Dağılımı — same sla_breached basis
                //    as section 4, grouped by priority.
                Section::make('Öncelik Bazlı SLA Dağılımı')
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

                                        $sealed = (int) $stat->on_time_count + (int) $stat->breached_count;
                                        $rate   = $sealed > 0
                                            ? round(((int) $stat->on_time_count / $sealed) * 100, 1)
                                            : 0;

                                        return [
                                            'priority_label' => $priorityEnum?->getLabel() ?? 'Bilinmiyor',
                                            'priority_color' => $priorityEnum?->getColor() ?? 'gray',
                                            'stats'          => "{$stat->on_time_count} / {$sealed}",
                                            'percentage'     => '%' . number_format($rate, 1, ',', '.'),
                                            'raw_percentage' => $rate,
                                        ];
                                    });
                            })
                            ->columns(3)
                            ->grid(2)
                            ->schema([
                                TextEntry::make('priority_label')
                                    ->label('Görev Önceliği')
                                    ->weight('bold')
                                    ->badge()
                                    ->color(fn ($record) => $record['priority_color']),

                                TextEntry::make('stats')
                                    ->label('Zamanında / Sealed')
                                    ->icon('heroicon-m-clipboard-document-check')
                                    ->color('gray'),

                                TextEntry::make('percentage')
                                    ->label('Başarı Oranı')
                                    ->badge()
                                    ->color(fn ($state, $record) =>
                                        ($record['raw_percentage'] ?? 0) >= 80 ? 'success' : (($record['raw_percentage'] ?? 0) >= 50 ? 'warning' : 'danger')
                                    ),
                            ]),
                    ]),
            ]);
    }
}
