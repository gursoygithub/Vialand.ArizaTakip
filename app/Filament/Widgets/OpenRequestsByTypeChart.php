<?php

namespace App\Filament\Widgets;

use App\Models\Ticket;
use App\Models\TicketType;
use Filament\Widgets\ChartWidget;

/**
 * Open requests per request type, among those the viewer may see.
 */
class OpenRequestsByTypeChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected static ?string $maxHeight = '260px';

    protected static ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        return auth()->check();
    }

    public function getHeading(): ?string
    {
        return __('ui.dashboard_type_chart');
    }

    public function getDescription(): ?string
    {
        return __('ui.dashboard_type_chart_desc');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = Ticket::query()
            ->visibleInTeamWorkflow(auth()->user())
            ->whereIn('status', RequestOverviewStats::ACTIVE)
            ->selectRaw('ticket_type_id, COUNT(*) as total')
            ->groupBy('ticket_type_id')
            ->pluck('total', 'ticket_type_id');

        $types = TicketType::query()->ordered()->whereIn('id', $counts->keys())->get();

        return [
            'datasets' => [[
                'label' => __('ui.dashboard_open'),
                'data' => $types->map(fn (TicketType $type): int => (int) $counts[$type->id])->all(),
                'backgroundColor' => '#3b82f6',
                'borderRadius' => 6,
            ]],
            'labels' => $types->pluck('name')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
