<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\TeamTicketResource;
use App\Models\Employee;
use App\Services\TeamPerformanceService;
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
        return $this->teamInfolist($infolist);
    }

    /**
     * Team model: identity, teams, workload right now and the person's SLA
     * figures from TeamPerformanceService (same rules as the report — only
     * breaches attributed to the person count against them).
     */
    public function teamInfolist(Infolist $infolist): Infolist
    {
        /** @var Employee $record */
        $record = $this->getRecord();
        $service = app(TeamPerformanceService::class);
        $workload = $service->currentWorkload($record);
        $report = $service->personReport($record);
        $all = $report['summary'];
        $last30 = $report['last30'];

        $pct = fn (?float $value): string => $value === null ? '—' : '%'.number_format($value, 1, ',', '.');
        $holdText = fn (array $holds): string => $holds['count'] === 0
            ? __('ui.employee_holds_none')
            : __('ui.employee_holds_value', ['count' => $holds['count'], 'time' => TeamTicketResource::workingDuration($holds['minutes'])]);
        $holdHint = fn (array $holds): ?string => $holds['count'] === 0 ? null : collect($holds['reasons'])
            ->map(fn (int $count, string $reason): string => $reason.': '.$count)
            ->push($holds['overdue'] > 0 ? __('ui.employee_holds_overdue', ['count' => $holds['overdue']]) : null)
            ->filter()
            ->implode(' · ');
        $tone = fn (?float $value, ?float $target): string => match (true) {
            $value === null || $target === null => 'gray',
            $value >= $target => 'success',
            $value >= $target * 0.75 => 'warning',
            default => 'danger',
        };

        $summary = match (true) {
            $workload['breached'] > 0 => __('ui.employee_summary_overdue', ['count' => $workload['breached']]),
            $all['total'] === 0 => __('ui.employee_summary_empty'),
            default => __('ui.employee_summary_rates', [
                'last30' => $pct($last30['compliance']),
                'all' => $pct($all['compliance']),
                'target' => $pct($all['target']),
            ]),
        };

        $row = fn (TextEntry $component): array => (array) $component->getContainer()->getState();

        $breakdown = fn (string $name, array $rows, Infolists\Components\Component $first): Infolists\Components\RepeatableEntry => Infolists\Components\RepeatableEntry::make($name)
            ->label('')
            ->state($rows)
            ->columns(4)
            ->grid(2)
            ->schema([
                $first,
                TextEntry::make('ratio')
                    ->label(__('ui.employee_on_time_total'))
                    ->state(fn (TextEntry $component): string => $row($component)['on_time'].' / '.$row($component)['total'])
                    ->color('gray'),
                TextEntry::make('compliance')
                    ->label(__('ui.report_compliance'))
                    ->formatStateUsing(fn ($state): string => $pct($state))
                    ->color(fn (TextEntry $component): string => $tone($row($component)['compliance'], $row($component)['target']))
                    ->badge(),
                TextEntry::make('personal_breaches')
                    ->label(__('ui.report_personal_breaches'))
                    ->color(fn ($state): string => $state > 0 ? 'danger' : 'gray')
                    ->badge(),
            ]);

        return $infolist
            ->schema([
                Section::make(__('ui.employee'))
                    ->icon('heroicon-o-user-circle')
                    ->columns(4)
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

                        TextEntry::make('company.name')
                            ->label(__('ui.company'))
                            ->icon('heroicon-o-building-office-2')
                            ->placeholder('—'),

                        TextEntry::make('team_names')
                            ->label(__('ui.teams'))
                            ->state(EmployeeResource::teamLabels($record))
                            ->badge()
                            ->color('info')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('ui.employee_sla_analysis'))
                    ->description(__('ui.report_people_hint'))
                    ->icon('heroicon-o-presentation-chart-line')
                    ->schema([
                        TextEntry::make('summary_sentence')
                            ->label('')
                            ->state($summary)
                            ->columnSpanFull()
                            ->color($workload['breached'] > 0 ? 'danger' : null)
                            ->weight('medium'),

                        Grid::make(3)->schema([
                            Section::make(__('ui.employee_now'))
                                ->description(__('ui.employee_now_hint'))
                                ->compact()
                                ->columnSpan(1)
                                ->schema([
                                    TextEntry::make('held_requests')
                                        ->label(__('ui.employee_requests_on_them'))
                                        ->state($workload['active'])
                                        ->color($workload['active'] > 0 ? 'warning' : 'gray')
                                        ->badge(),
                                    TextEntry::make('overdue_requests')
                                        ->label(__('ui.employee_requests_overdue'))
                                        ->state($workload['breached'])
                                        ->color($workload['breached'] > 0 ? 'danger' : 'gray')
                                        ->badge(),
                                    TextEntry::make('open_holds')
                                        ->label(__('ui.employee_holds_open'))
                                        ->state($report['holds']['open'])
                                        ->color($report['holds']['open'] > 0 ? 'warning' : 'gray')
                                        ->badge(),
                                ]),

                            Section::make(__('ui.employee_last30'))
                                ->description(__('ui.employee_last30_hint'))
                                ->compact()
                                ->columnSpan(1)
                                ->schema([
                                    TextEntry::make('last30_compliance')
                                        ->label(__('ui.report_compliance'))
                                        ->state($pct($last30['compliance']))
                                        ->color($tone($last30['compliance'], $last30['target']))
                                        ->weight('bold')
                                        ->badge(),
                                    TextEntry::make('last30_total')
                                        ->label(__('ui.report_sealed'))
                                        ->state($last30['total'])
                                        ->color('gray')
                                        ->badge(),
                                    TextEntry::make('last30_breaches')
                                        ->label(__('ui.report_personal_breaches'))
                                        ->state($last30['personal_breaches'])
                                        ->color($last30['personal_breaches'] > 0 ? 'danger' : 'gray')
                                        ->badge(),
                                    TextEntry::make('last30_holds')
                                        ->label(__('ui.employee_holds'))
                                        ->state($holdText($report['holds_last30']))
                                        ->helperText($holdHint($report['holds_last30'])),
                                ]),

                            Section::make(__('ui.employee_all_time'))
                                ->description(__('ui.employee_all_time_hint'))
                                ->compact()
                                ->columnSpan(1)
                                ->schema([
                                    TextEntry::make('all_compliance')
                                        ->label(__('ui.report_compliance'))
                                        ->hint(__('ui.report_compliance_hint', ['on' => $all['on_time'], 'total' => $all['total']]))
                                        ->state($pct($all['compliance']))
                                        ->color($tone($all['compliance'], $all['target']))
                                        ->weight('bold')
                                        ->badge(),
                                    TextEntry::make('all_target')
                                        ->label(__('ui.employee_sla_goal'))
                                        ->state($pct($all['target']))
                                        ->color('info')
                                        ->badge(),
                                    TextEntry::make('all_resolution')
                                        ->label(__('ui.report_avg_resolution'))
                                        ->state($all['avg_resolution_minutes'] === null ? '—' : TeamTicketResource::workingDuration($all['avg_resolution_minutes'])),
                                    TextEntry::make('all_satisfaction')
                                        ->label(__('ui.report_satisfaction'))
                                        ->state($all['satisfaction'] === null ? '—' : __('ui.employee_satisfaction_value', [
                                            'score' => number_format($all['satisfaction'], 1, ',', '.'),
                                            'count' => $all['rated'],
                                        ])),
                                    TextEntry::make('all_quality')
                                        ->label(__('ui.employee_quality'))
                                        ->state(__('ui.employee_quality_value', ['reopened' => $all['reopened'], 'rejections' => $all['rejections']])),
                                    TextEntry::make('all_holds')
                                        ->label(__('ui.employee_holds'))
                                        ->state($holdText($report['holds']))
                                        ->helperText($holdHint($report['holds'])),
                                ]),
                        ]),
                    ]),

                Section::make(__('ui.employee_team_breakdown'))
                    ->description(__('ui.employee_team_breakdown_hint'))
                    ->icon('heroicon-o-user-group')
                    ->visible($report['teams'] !== [])
                    ->schema([
                        $breakdown('team_breakdown', $report['teams'], TextEntry::make('name')
                            ->label(__('ui.team'))
                            ->helperText(fn (TextEntry $component): ?string => $row($component)['type'] ?? null)
                            ->weight('bold')),
                    ]),

                Section::make(__('ui.employee_priority_sla'))
                    ->description(__('ui.employee_priority_breakdown_hint'))
                    ->icon('heroicon-o-funnel')
                    ->visible($report['priorities'] !== [])
                    ->schema([
                        $breakdown('priority_breakdown', $report['priorities'], TextEntry::make('priority')
                            ->label(__('ui.priority'))
                            ->badge()),
                    ]),
            ]);
    }
}
