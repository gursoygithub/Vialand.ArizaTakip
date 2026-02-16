<?php

namespace App\Filament\Pages;

use App\Enums\TaskStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonPeriod;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

class TaskKpiPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 100;

    public static function getNavigationLabel(): string
    {
        return __('ui.kpi_dashboard');
    }

    public function getTitle(): string|Htmlable
    {
        return __('ui.task_performance_dashboard');
    }

    protected static string $view = 'filament.pages.task-kpi-page';

    public static function getNavigationGroup(): ?string
    {
        return __('ui.reports');
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'start_date'   => now()->startOfMonth(),
            'end_date'     => now(),
            'completed_by' => null,
            'priority'     => null,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* FORM */
    /* ------------------------------------------------------------------ */

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('ui.task_filters'))
                    ->description(__('ui.filter_tasks_for_kpi_reporting'))
                    ->columns(2)
                    ->schema([
                        DatePicker::make('start_date')
                            ->label(__('ui.start_date'))
                            ->maxDate(now())
                            ->native(false)
                            ->displayFormat('d F Y')
                            ->reactive(),

                        DatePicker::make('end_date')
                            ->label(__('ui.end_date'))
                            ->minDate(fn (callable $get) => $get('start_date'))
                            ->maxDate(now())
                            ->native(false)
                            ->displayFormat('d F Y')
                            ->reactive(),

                        Select::make('completed_by')
                            ->hidden()
                            ->label(__('ui.closed_by'))
                            ->options(
                                User::query()
                                    ->whereNot('id', 1)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->searchable()
                            ->placeholder(__('ui.all')),

                        Select::make('priority')
                            ->hidden()
                            ->label(__('ui.priority'))
                            ->options(TaskPriorityEnum::class)
                            ->placeholder(__('ui.all')),
                    ]),
            ])
            ->statePath('data');
    }

    /* ------------------------------------------------------------------ */
    /* BASE QUERY */
    /* ------------------------------------------------------------------ */

    private function baseQuery()
    {
        $q = Task::query()
            ->whereBetween('task_date', [
                $this->data['start_date'],
                $this->data['end_date'],
            ]);

        if ($this->data['completed_by']) {
            $q->where('completed_by', $this->data['completed_by']);
        }

        if ($this->data['priority']) {
            $q->where('priority', $this->data['priority']);
        }

        return $q;
    }

    /* ------------------------------------------------------------------ */
    /* KPI DATA */
    /* ------------------------------------------------------------------ */

    public function getKpiData(): array
    {
        $query = $this->baseQuery();

        /* ---------------- KPI ---------------- */

        $total = (clone $query)->count();

        $pending = (clone $query)
            ->where('status', TaskStatusEnum::PENDING)
            ->count();

        $completed = (clone $query)
            ->where('status', TaskStatusEnum::COMPLETED)
            ->count();

        $winter = (clone $query)
            ->where('status', TaskStatusEnum::WINTER_MAINTENANCE)
            ->count();

        // ❗ Overdue = sadece pending + tarihi geçmiş
        $overdue = (clone $query)
            ->where('status', TaskStatusEnum::PENDING)
            ->where('due_date', '<', now())
            ->count();

        /* -------- Status × Priority Breakdown -------- */

        $statusPriority = [];

        foreach (TaskStatusEnum::cases() as $status) {
            $statusCount = 0;

            foreach (TaskPriorityEnum::cases() as $priority) {
                $count = (clone $query)
                    ->where('status', $status)
                    ->where('priority', $priority)
                    ->count();

                if ($count > 0) {
                    $statusPriority[$status->name][] = [
                        'priority' => $priority,
                        'count'    => $count,
                    ];
                    $statusCount += $count;
                }
            }

            // Eğer hiç priority bazlı veri yoksa, toplam status sayısını al
            if ($statusCount === 0) {
                $totalStatusCount = (clone $query)
                    ->where('status', $status)
                    ->count();

                if ($totalStatusCount > 0) {
                    $statusPriority[$status->name][] = [
                        'priority' => null,
                        'count'    => $totalStatusCount,
                    ];
                }
            }
        }

        /* -------- En çok kapatanlar -------- */

        $byCloser = (clone $query)
            ->where('status', TaskStatusEnum::COMPLETED)
            ->select('completed_by', DB::raw('COUNT(*) as cnt'))
            ->with('completedBy:id,name')
            ->groupBy('completed_by')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get()
            ->map(fn ($i) => [
                'name'  => $i->completedBy->name ?? __('ui.unknown'),
                'count' => $i->cnt,
            ]);

        /* -------- Günlük trend (sadece completed) -------- */

        $rawDaily = (clone $query)
            ->where('status', TaskStatusEnum::COMPLETED)
            ->whereNotNull('due_date')
            ->selectRaw('DATE(due_date) as date, COUNT(*) as cnt')
            ->groupBy('date')
            ->pluck('cnt', 'date');

        $period = CarbonPeriod::create(
            $this->data['start_date'],
            $this->data['end_date']
        );

        $daily = collect();

        foreach ($period as $date) {
            $key = $date->format('Y-m-d');

            $daily->push([
                'date'  => $key,
                'count' => $rawDaily[$key] ?? 0,
            ]);
        }

        return [
            'performance' => [
                'total'           => $total,
                'pending'         => $pending,
                'completed'       => $completed,
                'winter'          => $winter,
                'overdue'         => $overdue,
                'completion_rate' => $total > 0
                    ? round(($completed / $total) * 100, 1)
                    : 0,
            ],

            'status_priority' => $statusPriority,
            'by_closer'       => $byCloser,
            'daily'           => $daily,
        ];
    }

    /* ------------------------------------------------------------------ */

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('reset')
                ->label(__('ui.reset_filters'))
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->mount())
                ->color('gray'),
        ];
    }
}
