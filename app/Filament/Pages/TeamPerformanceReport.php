<?php

namespace App\Filament\Pages;

use App\Filament\Resources\TeamTicketResource;
use App\Models\TicketType;
use App\Services\TeamPerformanceService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Performance of the team model: summary, teams, people, types, companies.
 * Supervisors see their own teams; super_admin / ticket.view.all see all.
 */
class TeamPerformanceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.team-performance-report';

    /**
     * @var array{from?: string, to?: string, team_ids?: array<int, int>, ticket_type_id?: int|null}
     */
    public array $filters = [];

    public static function getNavigationGroup(): ?string
    {
        return __('ui.reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('ui.team_performance_report');
    }

    public function getTitle(): string
    {
        return __('ui.team_performance_report');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasRole('super_admin') || $user?->can('page_TeamPerformanceReport'));
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'team_ids' => [],
            'ticket_type_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('filters')
            ->columns(4)
            ->schema([
                Forms\Components\DatePicker::make('from')->label(__('ui.date_from'))->required()->live(),
                Forms\Components\DatePicker::make('to')->label(__('ui.date_to'))->required()->live(),
                Forms\Components\Select::make('team_ids')
                    ->label(__('ui.teams'))
                    ->multiple()
                    ->options(fn (): array => app(TeamPerformanceService::class)
                        ->teamsVisibleTo(auth()->user())->pluck('name', 'id')->all())
                    ->live(),
                Forms\Components\Select::make('ticket_type_id')
                    ->label(__('ui.ticket_type'))
                    ->options(fn (): array => TicketType::ordered()->pluck('name', 'id')->all())
                    ->live(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $from = CarbonImmutable::parse($this->filters['from'] ?? now()->startOfMonth())->startOfDay();
        $to = CarbonImmutable::parse($this->filters['to'] ?? now())->endOfDay();

        return app(TeamPerformanceService::class)->report($from, $to, auth()->user(), [
            'team_ids' => array_map('intval', $this->filters['team_ids'] ?? []),
            'ticket_type_id' => $this->filters['ticket_type_id'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'report' => $this->report(),
            'minutes' => fn (?int $minutes): string => $minutes === null ? '—' : TeamTicketResource::humanMinutes($minutes),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label(__('ui.export_csv'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => (bool) (auth()->user()?->hasRole('super_admin') || auth()->user()?->can('ticket.export')))
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasRole('super_admin') || auth()->user()?->can('ticket.export'), 403);

        $report = $this->report();
        $file = 'ekip-performans-'.($this->filters['from'] ?? '').'_'.($this->filters['to'] ?? '').'.csv';

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [__('ui.teams')], ';');
            fputcsv($out, [__('ui.name'), __('ui.ticket_type'), __('ui.report_sealed'), __('ui.report_on_time'), __('ui.report_breached'), __('ui.report_compliance'), __('ui.breach_dispatch'), __('ui.breach_handover'), __('ui.report_exempted'), __('ui.report_reopen_rate'), __('ui.report_satisfaction')], ';');
            foreach ($report['teams'] as $row) {
                fputcsv($out, [$row['name'], $row['type'], $row['total'], $row['on_time'], $row['breached'], $row['compliance'], $row['dispatch_delays'], $row['handover_breaches'], $row['exempted'], $row['reopen_rate'], $row['satisfaction']], ';');
            }

            fputcsv($out, [], ';');
            fputcsv($out, [__('ui.report_people')], ';');
            fputcsv($out, [__('ui.name'), __('ui.company'), __('ui.report_sealed'), __('ui.report_resolved'), __('ui.report_personal_breaches'), __('ui.report_compliance'), __('ui.report_reopened'), __('ui.report_rejections'), __('ui.report_satisfaction'), __('ui.report_avg_resolution')], ';');
            foreach ($report['people'] as $row) {
                fputcsv($out, [$row['name'], $row['company'], $row['total'], $row['resolved'], $row['personal_breaches'], $row['compliance'], $row['reopened'], $row['rejections'], $row['satisfaction'], $row['avg_resolution_minutes']], ';');
            }

            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
