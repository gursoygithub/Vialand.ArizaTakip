<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TeamTicketResource\Pages\ListTeamTickets;
use App\Models\Ticket;
use Filament\Widgets\ChartWidget;

/**
 * Status mix of the visible requests opened in the last 30 days.
 */
class RequestsByStatusChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static ?string $maxHeight = '260px';

    protected static ?string $pollingInterval = '120s';

    /**
     * Chart colours per Filament colour name.
     *
     * @var array<string, string>
     */
    private const COLORS = [
        'info' => '#3b82f6',
        'warning' => '#f59e0b',
        'primary' => '#6366f1',
        'success' => '#22c55e',
        'gray' => '#9ca3af',
        'danger' => '#ef4444',
    ];

    public static function canView(): bool
    {
        return auth()->check();
    }

    public function getHeading(): ?string
    {
        return __('ui.dashboard_status_chart');
    }

    public function getDescription(): ?string
    {
        return __('ui.dashboard_last_30_days');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = Ticket::query()
            ->visibleInTeamWorkflow(auth()->user())
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = collect(ListTeamTickets::STATUSES)
            ->filter(fn (TaskStatusEnum $status): bool => (int) ($counts[$status->value] ?? 0) > 0)
            ->values();

        return [
            'datasets' => [[
                'data' => $statuses->map(fn (TaskStatusEnum $status): int => (int) $counts[$status->value])->all(),
                'backgroundColor' => $statuses->map(fn (TaskStatusEnum $status): string => self::COLORS[$status->getColor()] ?? '#9ca3af')->all(),
            ]],
            'labels' => $statuses->map(fn (TaskStatusEnum $status): string => $status->getLabel())->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'right']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }
}
