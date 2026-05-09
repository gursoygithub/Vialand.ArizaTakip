<?php

namespace App\Filament\Widgets;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Models\Ticket;
use Filament\Widgets\ChartWidget;

class TicketsByPriorityChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '60s';

    // Heading resolved via getHeading() so __() can be used.
    protected static ?string $heading = null;

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 1;

    public function getHeading(): ?string
    {
        return __('ui.widget_priority_chart_heading');
    }

    public function getDescription(): ?string
    {
        return __('ui.widget_priority_chart_desc');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $priorityOrder = [
            TaskPriorityEnum::Low,
            TaskPriorityEnum::Medium,
            TaskPriorityEnum::High,
            TaskPriorityEnum::Urgent,
        ];

        $colorMap = [
            TaskPriorityEnum::Low->value     => '#22c55e', // green
            TaskPriorityEnum::Medium->value  => '#eab308', // yellow
            TaskPriorityEnum::High->value    => '#f97316', // orange
            TaskPriorityEnum::Urgent->value  => '#ef4444', // red
        ];

        $openStatuses = [
            TaskStatusEnum::OPEN->value,
            TaskStatusEnum::ASSIGNED->value,
            TaskStatusEnum::IN_PROGRESS->value,
            TaskStatusEnum::ON_HOLD->value,
        ];

        $counts = Ticket::query()
            ->visibleBy(auth()->user())
            ->whereIn('status', $openStatuses)
            ->selectRaw('priority, COUNT(*) as total')
            ->groupBy('priority')
            ->pluck('total', 'priority')
            ->all();

        $labels = [];
        $values = [];
        $colors = [];

        foreach ($priorityOrder as $priority) {
            $labels[] = $priority->getLabel();
            $values[] = (int) ($counts[$priority->value] ?? 0);
            $colors[] = $colorMap[$priority->value];
        }

        return [
            'datasets' => [[
                'label'           => __('ui.widget_priority_chart_dataset'),
                'data'            => $values,
                'backgroundColor' => $colors,
                'borderWidth'     => 0,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins'   => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks'       => [
                        'precision' => 0,
                    ],
                ],
            ],
            'maintainAspectRatio' => false,
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->hasRole('super_admin')
            || $user->can('widget_TicketsByPriorityChart');
    }
}
