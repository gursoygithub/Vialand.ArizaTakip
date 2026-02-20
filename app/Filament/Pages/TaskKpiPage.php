<?php

namespace App\Filament\Pages;

use App\Enums\TaskStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Models\Task;
use App\Models\User;
use App\Models\Employee;
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
    protected static string $view = 'filament.pages.task-kpi-page';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'start_date'   => now()->startOfMonth()->format('Y-m-d'),
            'end_date'     => now()->format('Y-m-d'),
            'completed_by' => null,
            'employee_id'  => null,
        ]);
    }

    public static function getNavigationLabel(): string { return __('ui.kpi_dashboard'); }
    public function getTitle(): string|Htmlable { return __('ui.task_performance_dashboard'); }
    public static function getNavigationGroup(): ?string { return __('ui.reports'); }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('ui.task_filters'))
                    ->columns(4)
                    ->schema([
                        DatePicker::make('start_date')->label(__('ui.start_date'))->native(false)->reactive(),
                        DatePicker::make('end_date')->label(__('ui.end_date'))->native(false)->reactive(),
                        Select::make('completed_by')
                            ->label(__('ui.closed_by'))
                            ->options(User::query()->whereNot('id', 1)->pluck('name', 'id'))
                            ->searchable()
                            ->reactive(),
                        Select::make('employee_id')
                            ->label(__('ui.assigned_employee'))
                            ->options(Employee::query()->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->reactive(),
                    ]),
            ])
            ->statePath('data');
    }

    private function baseQuery()
    {
        $q = Task::query()
            ->whereBetween('task_date', [
                $this->data['start_date'],
                $this->data['end_date'],
            ]);

        if (!empty($this->data['completed_by'])) {
            $q->where('completed_by', $this->data['completed_by']);
        }

        if (!empty($this->data['employee_id'])) {
            $q->where('employee_id', $this->data['employee_id']);
        }

        return $q;
    }

    public function getKpiData(): array
    {
        $query = $this->baseQuery();

        // 1. Temel Sayılar
        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', TaskStatusEnum::COMPLETED)->count();
        $pending = (clone $query)->where('status', TaskStatusEnum::PENDING)->count();
        $winter = (clone $query)->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->count();

        // 2. Ortalama Kapatma Hızı
        $avgDays = (clone $query)
            ->where('status', TaskStatusEnum::COMPLETED)
            ->whereNotNull('due_date')
            ->whereNotNull('task_date')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, task_date, due_date)) / 24 as avg_time')
            ->value('avg_time');

        // 3. Status & Priority Dağılımı
        $statusPriority = [];
        foreach (TaskStatusEnum::cases() as $status) {
            foreach (TaskPriorityEnum::cases() as $priority) {
                $cnt = (clone $query)->where('status', $status)->where('priority', $priority)->count();
                if ($cnt > 0) {
                    $statusPriority[$status->name][] = ['priority' => $priority, 'count' => $cnt];
                }
            }
        }

        // 4. Günlük Trend
        $rawDaily = (clone $query)
            ->where('status', TaskStatusEnum::COMPLETED)
            ->whereNotNull('due_date')
            ->select(DB::raw('DATE(due_date) as date'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('date')
            ->pluck('cnt', 'date')
            ->toArray();

        $daily = collect();
        foreach (CarbonPeriod::create($this->data['start_date'], $this->data['end_date']) as $date) {
            $key = $date->format('Y-m-d');
            $daily->push(['date' => $key, 'count' => $rawDaily[$key] ?? 0]);
        }

        // 5. Personel Listeleri
        $byCloser = (clone $query)
            ->where('status', TaskStatusEnum::COMPLETED)
            ->select('completed_by', DB::raw('COUNT(*) as cnt'))
            ->with('completedBy:id,name')
            ->groupBy('completed_by')
            ->orderByDesc('cnt')->limit(5)->get();

        $byEmployee = (clone $query)
            ->select('employee_id', DB::raw('COUNT(*) as cnt'))
            ->with('employee:id,name')
            ->groupBy('employee_id')
            ->orderByDesc('cnt')->limit(5)->get();

        return [
            'performance' => [
                'total'           => $total,
                'completed'       => $completed,
                'pending'         => $pending,
                'winter'          => $winter,
                'avg_days'        => round($avgDays ?? 0, 1),
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            ],
            'status_priority' => $statusPriority,
            'daily'           => $daily,
            'by_closer'       => $byCloser->map(fn($i) => ['name' => $i->completedBy->name ?? '?', 'count' => $i->cnt]),
            'by_employee'     => $byEmployee->map(fn($i) => ['name' => $i->employee->name ?? '?', 'count' => $i->cnt]),
        ];
    }

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