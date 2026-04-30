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
            // Supervisor: show own area
            $supervisorAreaId = \App\Models\Group::whereHas('members', fn ($q) =>
                $q->whereHas('employee', fn ($eq) =>
                    $eq->where('email', auth()->user()->email)
                )
            )->value('area_id');

            if ($supervisorAreaId) {
                $this->teamStats = $service->getTeamStats($supervisorAreaId, $from, $to);
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

    public function exportCsv(): void
    {
        // Delegate to Filament export when available
        $this->loadStats();

        $csv = "Name,Total,On Time,Breach Count,Compliance Rate,Avg Resolution (min)\n";

        foreach ($this->teamStats as $row) {
            $csv .= implode(',', [
                $row['user']->name,
                $row['total'],
                $row['on_time'],
                $row['breach_count'],
                $row['compliance_rate'] . '%',
                $row['avg_resolution_minutes'],
            ]) . "\n";
        }

        $filename = 'performance_' . now()->format('Y_m_d') . '.csv';

        response()->streamDownload(fn () => print($csv), $filename)->send();
    }
}
