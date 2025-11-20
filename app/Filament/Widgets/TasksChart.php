<?php

    namespace App\Filament\Widgets;

    use App\Enums\TaskStatusEnum;
    use App\Enums\TaskTypeEnum;
    use App\Models\Task;
    use Filament\Widgets\ChartWidget;
    use Illuminate\Support\Facades\DB;

    class TasksChart extends ChartWidget
    {
        public function getHeading(): string
        {
            return __('ui.tasks_by_type');
        }

        protected static ?int $sort = 2;

        protected int | string | array $columnSpan = 'full';

        public ?string $filter = 'all';

        protected function getData(): array
        {
            $query = Task::query();

            // Filter logic
            if ($this->filter !== 'all') {
                $query->where('status', $this->filter);
            }

            $taskCounts = $query
                ->select('type_id', DB::raw('count(*) as count'))
                ->groupBy('type_id')
                ->pluck('count', 'type_id')
                ->toArray();

            $labels = [];
            $data = [];
            $colors = [];

            foreach (TaskTypeEnum::cases() as $type) {
                $labels[] = $type->getLabel();
                $data[] = $taskCounts[$type->value] ?? 0;

                // Convert Filament color names to hex
                $colors[] = match($type->getColor()) {
                    'success' => 'rgb(34, 197, 94)',
                    'warning' => 'rgb(251, 191, 36)',
                    'primary' => 'rgb(59, 130, 246)',
                    default => 'rgb(156, 163, 175)',
                };
            }

            return [
                'datasets' => [
                    [
                        'label' => __('ui.number_of_tasks'),
                        'data' => $data,
                        'backgroundColor' => $colors,
                        'borderColor' => $colors,
                        'borderWidth' => 2,
                        'borderRadius' => 8,
                        'barThickness' => 60,
                    ],
                ],
                'labels' => $labels,
            ];
        }

        protected function getType(): string
        {
            return 'bar';
        }

        protected function getOptions(): array
        {
            return [
                'plugins' => [
                    'legend' => [
                        'display' => false,
                        'position' => 'top',
                    ],
                    'tooltip' => [
                        'enabled' => true,
                        'backgroundColor' => 'rgba(0, 0, 0, 0.8)',
                        'padding' => 12,
                        'cornerRadius' => 8,
                    ],
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'ticks' => [
                            'precision' => 0,
                        ],
                        'grid' => [
                            'display' => true,
                            'color' => 'rgba(0, 0, 0, 0.05)',
                        ],
                    ],
                    'x' => [
                        'grid' => [
                            'display' => false,
                        ],
                    ],
                ],
                'maintainAspectRatio' => false,
                'responsive' => true,
            ];
        }

        protected function getFilters(): ?array
        {
            $filters = ['all' => __('ui.all')];

            foreach (TaskStatusEnum::cases() as $status) {
                $filters[$status->value] = $status->getLabel();
            }

            return $filters;
        }
    }