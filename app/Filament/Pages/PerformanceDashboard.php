<?php

namespace App\Filament\Pages;

use App\Models\Area;
use App\Services\PerformanceService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class PerformanceDashboard extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.performance-dashboard';

    public ?string $dateFrom = null;
    public ?string $dateTo   = null;
    public ?int    $areaId   = null;

    public array $overview   = [];
    public Collection $teamStats;
    public Collection $regionBreakdown;

    public static function getNavigationGroup(): ?string
    {
        return __('ui.reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('ui.performance_dashboard');
    }

    /**
     * Override Filament's default page-heading fallback (which would derive
     * "Performance Dashboard" from the class basename) so the heading bar
     * matches the navigation label in Turkish.
     */
    public function getTitle(): string
    {
        return __('ui.performance_dashboard');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('report.view') ?? false;
    }

    public function mount(): void
    {
        $this->teamStats       = collect();
        $this->regionBreakdown = collect();
        $this->dateFrom        = now()->startOfMonth()->toDateString();
        $this->dateTo          = now()->endOfMonth()->toDateString();
        $this->loadStats();
    }

    /**
     * Area IDs the current user is allowed to see, used for both the dropdown
     * and the stats-iteration loop so both stay in sync.
     *
     * ticket.view.all → every area (admin sees all).
     * Others → union of company-scoped areas + group-member areas + managed areas.
     * Mirrors the dual-path used in Ticket::scopeVisibleBy() and the supervisor
     * branch added in 2ef9df8.
     */
    private function resolveVisibleAreaIds(): Collection
    {
        $user = auth()->user();

        if (!$user) {
            return collect();
        }

        if ($user->can('ticket.view.all')) {
            return Area::pluck('id');
        }

        $employee   = \App\Models\Employee::where('email', $user->email)->first();
        $employeeId = $employee?->id;
        $companyIds = $user->scopedCompanyIds();

        $companyAreaIds = !empty($companyIds)
            ? Area::whereIn('company_id', $companyIds)->pluck('id')
            : collect();

        $memberAreaIds  = $employeeId
            ? \App\Models\Group::whereHas('members', fn ($q) => $q->where('employee_id', $employeeId))->pluck('area_id')
            : collect();

        $managedAreaIds = $employeeId
            ? \App\Models\Group::where('employee_id', $employeeId)->pluck('area_id')
            : collect();

        return $companyAreaIds->merge($memberAreaIds)->merge($managedAreaIds)->unique()->values();
    }

    /**
     * Eloquent collection of Area models the current user can see,
     * ordered by name. Used by the Blade dropdown.
     */
    public function getVisibleAreas(): \Illuminate\Database\Eloquent\Collection
    {
        return Area::whereIn('id', $this->resolveVisibleAreaIds())->orderBy('name')->get();
    }

    public function loadStats(): void
    {
        if (!auth()->user()?->can('report.view')) {
            return;
        }

        $service = app(PerformanceService::class);
        $from    = Carbon::parse($this->dateFrom)->startOfDay();
        $to      = Carbon::parse($this->dateTo)->endOfDay();

        $selectedArea = $this->areaId ?: null;

        $this->overview        = $service->getOverview($from, $to, null, $selectedArea);
        $this->regionBreakdown = $service->getRegionBreakdown($from, $to, null, $selectedArea);

        if ($selectedArea && auth()->user()?->can('ticket.view.all')) {
            $this->teamStats = $service->getTeamStats($selectedArea, $from, $to);
        } elseif (auth()->user()?->can('ticket.view.all')) {
            // Admin: iterate all visible areas (= all areas for ticket.view.all users).
            $allStats = collect();
            $this->resolveVisibleAreaIds()->each(function ($id) use ($service, $from, $to, &$allStats) {
                $allStats = $allStats->merge($service->getTeamStats($id, $from, $to));
            });
            $this->teamStats = $allStats->unique(fn ($s) => $s['user']->id)->sortByDesc('compliance_rate')->values();
        } else {
            // Non-admin: resolve visible areas. If the user has selected a specific area
            // and it falls within their visible set, scope to that area only.
            // Otherwise iterate the full visibility set.
            $areaIds = $this->resolveVisibleAreaIds();
            if ($areaIds->isNotEmpty()) {
                $iterateIds = ($selectedArea && $areaIds->contains($selectedArea))
                    ? collect([$selectedArea])
                    : $areaIds;
                $allStats = collect();
                $iterateIds->each(function ($id) use ($service, $from, $to, &$allStats) {
                    $allStats = $allStats->merge($service->getTeamStats($id, $from, $to));
                });
                $this->teamStats = $allStats->unique(fn ($s) => $s['user']->id)->values();
            }
        }
    }

    public function setDateRange(string $preset): void
    {
        match ($preset) {
            'this_week'    => [$this->dateFrom = now()->startOfWeek()->toDateString(),
                               $this->dateTo   = now()->endOfWeek()->toDateString()],
            'this_month'   => [$this->dateFrom = now()->startOfMonth()->toDateString(),
                               $this->dateTo   = now()->endOfMonth()->toDateString()],
            'last_month'   => [$this->dateFrom = now()->subMonth()->startOfMonth()->toDateString(),
                               $this->dateTo   = now()->subMonth()->endOfMonth()->toDateString()],
            'last_30_days' => [$this->dateFrom = now()->subDays(30)->toDateString(),
                               $this->dateTo   = now()->toDateString()],
            'this_quarter' => [$this->dateFrom = now()->firstOfQuarter()->toDateString(),
                               $this->dateTo   = now()->lastOfQuarter()->toDateString()],
            'this_year'    => [$this->dateFrom = now()->startOfYear()->toDateString(),
                               $this->dateTo   = now()->endOfYear()->toDateString()],
            default        => null,
        };
        $this->loadStats();
    }

    public function resetFilters(): void
    {
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo   = now()->endOfMonth()->toDateString();
        $this->areaId   = null;
        $this->loadStats();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Çıktı Al')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->form([
                    Radio::make('format')
                        ->label('Dosya Formatı')
                        ->options([
                            'pdf'  => 'PDF (Yazdırılabilir Rapor)',
                            'xlsx' => 'Excel (xlsx)',
                        ])
                        ->default('pdf')
                        ->required(),
                    CheckboxList::make('sections')
                        ->label('Dahil Edilecek Bölümler')
                        ->options([
                            'kpi'      => 'KPI Özeti',
                            'priority' => 'Öncelik Dağılımı',
                            'region'   => 'Bölge Dağılımı',
                            'team'     => 'Teknisyen Performansı',
                        ])
                        ->default(['kpi', 'priority', 'region', 'team'])
                        ->required()
                        ->columns(2),
                ])
                ->action(function (array $data) {
                    return $data['format'] === 'pdf'
                        ? $this->exportPdf($data['sections'])
                        : $this->exportExcel($data['sections']);
                }),
        ];
    }

    protected function exportPdf(array $sections): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->loadStats();

        $context = [
            'overview'        => in_array('kpi', $sections) ? $this->overview : null,
            'regionBreakdown' => in_array('region', $sections) ? $this->regionBreakdown : null,
            'teamStats'       => in_array('team', $sections) ? $this->teamStats : null,
            'showPriority'    => in_array('priority', $sections),
            'dateFrom'        => $this->dateFrom,
            'dateTo'          => $this->dateTo,
            'areaName'        => $this->areaId
                ? Area::find($this->areaId)?->name
                : 'Tümü',
            'generatedAt'     => now()->format('d.m.Y H:i'),
            'generatedBy'     => auth()->user()->name,
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.performance-report', $context)
            ->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            'performans-raporu-' . now()->format('Y-m-d-Hi') . '.pdf'
        );
    }

    protected function exportExcel(array $sections): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->loadStats();

        $export = new \App\Exports\PerformanceReportExport(
            $sections,
            $this->overview,
            $this->regionBreakdown,
            $this->teamStats,
            $this->dateFrom,
            $this->dateTo,
            $this->areaId ? Area::find($this->areaId)?->name : 'Tümü'
        );

        return \Maatwebsite\Excel\Facades\Excel::download(
            $export,
            'performans-raporu-' . now()->format('Y-m-d-Hi') . '.xlsx'
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(\App\Models\Ticket::query()->whereRaw('1=0')) // placeholder
            ->columns([])
            ->paginated(false);
    }

    /**
     * Stream the team stats as a CSV download.
     *
     * Returns the StreamedResponse rather than calling ->send() — that
     * pattern flushes mid-Livewire-request and is unreliable. Livewire 3
     * picks up a returned download response from an action.
     *
     * UTF-8 BOM is prepended so Excel on Windows opens Turkish characters
     * (İ, ş, ğ, ç, ü, ö) without mojibake. Rows are written via fputcsv
     * so commas/quotes inside names are properly escaped.
     */
    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->loadStats();

        $teamStats = $this->teamStats;
        $filename  = 'performans_' . now()->format('Y_m_d') . '.csv';

        return response()->streamDownload(function () use ($teamStats) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM for Excel.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                __('ui.page_performance_csv_employee'),
                __('ui.total_tickets'),
                __('ui.on_time'),
                __('ui.page_performance_col_breached'),
                __('ui.page_performance_csv_compliance'),
                'Ortalama Çözüm Süresi - Brüt (dk)',
                'Ortalama Çözüm Süresi - Net (dk)',
                __('ui.page_performance_csv_response'),
            ]);

            foreach ($teamStats as $row) {
                fputcsv($out, [
                    $row['user']->name,
                    $row['total_assigned'],
                    $row['closed_on_time'],
                    $row['closed_breached'],
                    '%' . number_format($row['sla_compliance_rate'], 1, ',', '.'),
                    $row['avg_resolution_minutes'],
                    $row['avg_resolution_active_minutes'],
                    $row['avg_response_time_minutes'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
