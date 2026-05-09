<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use App\Models\Ticket;
use Filament\Widgets\ChartWidget;

class TicketsByStatusChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '60s';

    // Heading resolved via getHeading() so __() can be used.
    protected static ?string $heading = null;

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 1;

    public function getHeading(): ?string
    {
        return __('ui.widget_status_chart_heading');
    }

    public function getDescription(): ?string
    {
        return __('ui.widget_status_chart_desc');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $statusOrder = [
            TaskStatusEnum::OPEN,
            TaskStatusEnum::ASSIGNED,
            TaskStatusEnum::IN_PROGRESS,
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::ON_HOLD,
            TaskStatusEnum::CANCELLED,
        ];

        $colorMap = [
            TaskStatusEnum::OPEN->value         => '#3b82f6', // blue
            TaskStatusEnum::ASSIGNED->value     => '#f59e0b', // amber
            TaskStatusEnum::IN_PROGRESS->value  => '#8b5cf6', // purple
            TaskStatusEnum::RESOLVED->value     => '#14b8a6', // teal
            TaskStatusEnum::CLOSED->value       => '#22c55e', // green
            TaskStatusEnum::ON_HOLD->value      => '#9ca3af', // gray
            TaskStatusEnum::CANCELLED->value    => '#ef4444', // red
        ];

        $counts = Ticket::query()
            ->visibleBy(auth()->user())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $labels = [];
        $values = [];
        $colors = [];

        foreach ($statusOrder as $status) {
            $count = (int) ($counts[$status->value] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $labels[] = $status->getLabel();
            $values[] = $count;
            $colors[] = $colorMap[$status->value];
        }

        if (empty($values)) {
            return [
                'datasets' => [[
                    'label'           => __('ui.widget_status_chart_dataset'),
                    'data'            => [1],
                    'backgroundColor' => ['#e5e7eb'],
                ]],
                'labels' => [__('ui.widget_status_chart_no_data')],
            ];
        }

        return [
            'datasets' => [[
                'label'           => __('ui.widget_status_chart_dataset'),
                'data'            => $values,
                'backgroundColor' => $colors,
                'borderWidth'     => 1,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
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
            || $user->can('widget_TicketsByStatusChart');
    }
}
