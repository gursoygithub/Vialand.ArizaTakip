<?php

namespace App\Filament\Widgets;

use App\Models\Ticket;
use Filament\Widgets\ChartWidget;

class SlaComplianceTrendChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '300s';

    protected static ?string $heading = 'SLA Performans Trendi';

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    public function getDescription(): ?string
    {
        return 'Son 30 gün';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $start = now()->subDays(29)->startOfDay();
        $end = now()->endOfDay();

        $rows = Ticket::query()
            ->visibleBy(auth()->user())
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$start, $end])
            ->selectRaw('DATE(resolved_at) as day, sla_breached, COUNT(*) as total')
            ->groupBy('day', 'sla_breached')
            ->get();

        $labels = [];
        $onTime = [];
        $breached = [];

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $labels[] = $d->format('d.m');

            $dayRows = $rows->filter(fn ($row) => (string) $row->day === $key);
            $onTimeCount = (int) optional($dayRows->firstWhere('sla_breached', 0))->total;
            $onTimeCountAlt = (int) optional($dayRows->firstWhere('sla_breached', false))->total;
            $breachedCount = (int) optional($dayRows->firstWhere('sla_breached', 1))->total;
            $breachedCountAlt = (int) optional($dayRows->firstWhere('sla_breached', true))->total;

            $onTime[] = max($onTimeCount, $onTimeCountAlt);
            $breached[] = max($breachedCount, $breachedCountAlt);
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Zamanında Kapatılan',
                    'data'            => $onTime,
                    'borderColor'     => '#22c55e',
                    'backgroundColor' => 'rgba(34,197,94,0.15)',
                    'fill'            => true,
                    'tension'         => 0.3,
                ],
                [
                    'label'           => 'SLA İhlalli Kapatılan',
                    'data'            => $breached,
                    'borderColor'     => '#ef4444',
                    'backgroundColor' => 'rgba(239,68,68,0.15)',
                    'fill'            => true,
                    'tension'         => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'top',
                ],
            ],
            'scales' => [
                'y' => [
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
            || $user->can('widget_SlaComplianceTrendChart');
    }
}
