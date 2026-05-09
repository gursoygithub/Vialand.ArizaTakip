<?php

namespace App\Filament\Pages;

use App\Models\Area;
use App\Services\PerformanceService;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
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

    public function loadStats(): void
    {
        if (!auth()->user()?->can('report.view')) {
            return;
        }

        $service = app(PerformanceService::class);
        $from    = Carbon::parse($this->dateFrom)->startOfDay();
        $to      = Carbon::parse($this->dateTo)->endOfDay();

        $this->overview        = $service->getOverview($from, $to);
        $this->regionBreakdown = $service->getRegionBreakdown($from, $to);

        if ($this->areaId && auth()->user()?->can('ticket.view.all')) {
            $this->teamStats = $service->getTeamStats($this->areaId, $from, $to);
        } elseif (auth()->user()?->can('ticket.view.all')) {
            // Admin: show all areas
            $allStats = collect();
            Area::pluck('id')->each(function ($id) use ($service, $from, $to, &$allStats) {
                $allStats = $allStats->merge($service->getTeamStats($id, $from, $to));
            });
            $this->teamStats = $allStats->unique(fn ($s) => $s['user']->id)->sortByDesc('compliance_rate')->values();
        } else {
            // Supervisor: resolve areas from BOTH group membership (group_members rows)
            // and groups where the user is the named manager (groups.employee_id).
            // Mirrors the dual-path added to Ticket::scopeVisibleBy() in d5c1a30.
            $supervisorEmployee = \App\Models\Employee::where('email', auth()->user()->email)->first();
            $employeeId         = $supervisorEmployee?->id;

            if ($employeeId) {
                $memberAreaIds  = \App\Models\Group::whereHas('members',
                    fn ($q) => $q->where('employee_id', $employeeId)
                )->pluck('area_id');
                $managedAreaIds = \App\Models\Group::where('employee_id', $employeeId)->pluck('area_id');
                $areaIds        = $memberAreaIds->merge($managedAreaIds)->unique()->values();

                if ($areaIds->isNotEmpty()) {
                    $allStats = collect();
                    $areaIds->each(function ($id) use ($service, $from, $to, &$allStats) {
                        $allStats = $allStats->merge($service->getTeamStats($id, $from, $to));
                    });
                    $this->teamStats = $allStats->unique(fn ($s) => $s['user']->id)->values();
                }
            }
        }
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
                'Personel',
                'Toplam Talep',
                'Zamanında',
                'İhlal',
                'Uyum %',
                'Ort. Çözüm (dk)',
                'Ort. Yanıt (dk)',
            ]);

            foreach ($teamStats as $row) {
                fputcsv($out, [
                    $row['user']->name,
                    $row['total_assigned'],
                    $row['closed_on_time'],
                    $row['closed_breached'],
                    '%' . number_format($row['sla_compliance_rate'], 1, ',', '.'),
                    $row['avg_resolution_minutes'],
                    $row['avg_response_time_minutes'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
