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
            $types = TaskTypeEnum::cases();
            $labels = [];
            foreach ($types as $type) {
                $labels[] = $type->getLabel();
            }

            // Belirli bir durum seçiliyse: tek dataset (tüm çubuklar aynı renkte)
            if ($this->filter !== 'all') {
                $query = Task::query();
                $query->where('status', $this->filter);

                // type_id enum olarak dönebilir, bu yüzden doğrudan pluck yerine satırları alıp normalize ediyoruz
                $taskCounts = [];
                $rows = $query
                    ->select('type_id', DB::raw('count(*) as count'))
                    ->groupBy('type_id')
                    ->get();

                foreach ($rows as $row) {
                    $key = $row->type_id instanceof \BackedEnum ? $row->type_id->value : $row->type_id;
                    $taskCounts[(int) $key] = (int) $row->count;
                }

                $statusColorName = 'secondary';
                $filterValue = is_numeric($this->filter) ? (int) $this->filter : $this->filter;
                $statusColorName = match ($filterValue) {
                    TaskStatusEnum::PENDING->value, 'pending' => 'warning',
                    TaskStatusEnum::COMPLETED->value, 'completed' => 'success',
                    TaskStatusEnum::WINTER_MAINTENANCE->value, 'kış bakımı', 'kis_bakimi', 'winter_maintenance' => 'primary',
                    default => 'secondary',
                };

                $statusColorHex = match ($statusColorName) {
                    'success' => 'rgb(34, 197, 94)',
                    'warning' => 'rgb(251, 191, 36)',
                    'primary' => 'rgb(59, 130, 246)',
                    'secondary' => 'rgb(156, 163, 175)',
                    default => 'rgb(156, 163, 175)',
                };

                $data = [];
                $colors = [];
                foreach ($types as $type) {
                    $typeKey = $type instanceof \BackedEnum ? $type->value : $type;
                    $data[] = $taskCounts[(int) $typeKey] ?? 0;
                    $colors[] = $statusColorHex;
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

            // 'all' seçiliyse: her durum için ayrı dataset oluşturarak hangi kısmın hangi duruma ait olduğu görünür
            $rows = Task::select('type_id', 'status', DB::raw('count(*) as count'))
                ->groupBy('type_id', 'status')
                ->get();

            $countsMap = [];
            foreach ($rows as $row) {
                $statusKey = $row->status instanceof \BackedEnum ? (string) $row->status->value : (string) $row->status;
                $typeKey = $row->type_id instanceof \BackedEnum ? $row->type_id->value : $row->type_id;
                $countsMap[$statusKey][(int) $typeKey] = (int) $row->count;
            }

            $datasets = [];
            foreach (TaskStatusEnum::cases() as $status) {
                $data = [];
                foreach ($types as $type) {
                    $typeKey = $type instanceof \BackedEnum ? $type->value : $type;
                    $data[] = $countsMap[(string) $status->value][(int) $typeKey] ?? 0;
                }

                $colorName = $status->getColor();
                $colorHex = match ($colorName) {
                    'success' => 'rgb(34, 197, 94)',
                    'warning' => 'rgb(251, 191, 36)',
                    'primary' => 'rgb(59, 130, 246)',
                    'secondary' => 'rgb(156, 163, 175)',
                    default => 'rgb(156, 163, 175)',
                };

                $datasets[] = [
                    'label' => $status->getLabel(),
                    'data' => $data,
                    'backgroundColor' => $colorHex,
                    'borderColor' => $colorHex,
                    'borderWidth' => 2,
                    'borderRadius' => 8,
                    'barThickness' => 40,
                ];
            }

            return [
                'datasets' => $datasets,
                'labels' => $labels,
            ];
        }

        protected function getType(): string
        {
            return 'bar';
        }

        protected function getOptions(): array
        {
            $stacked = $this->filter === 'all';

            return [
                'plugins' => [
                    'legend' => [
                        'display' => $stacked,
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
                        'stacked' => $stacked,
                    ],
                    'x' => [
                        'grid' => [
                            'display' => false,
                        ],
                        'stacked' => $stacked,
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

        public static function canView(): bool
        {
            return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_TasksChart');
        }
    }