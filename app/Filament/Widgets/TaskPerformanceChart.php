<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

class TaskPerformanceChart extends ChartWidget
{
    protected static ?string $heading = 'SLA Başarı Trendi (Son 30 Gün)';
    protected static string $color = 'success';

    protected function getData(): array
    {
        $data = Trend::query(Task::where('sla_outcome', 'SUCCESS'))
            ->between(start: now()->subDays(30), end: now())
            ->perDay()
            ->count();

        return [
            'datasets' => [
                [
                    'label' => 'Zamanında Bitirilenler',
                    'data' => $data->map(fn (TrendValue $value) => $value->aggregate),
                    'borderColor' => '#10b981',
                    'fill' => 'start',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                ],
            ],
            'labels' => $data->map(fn (TrendValue $value) => \Carbon\Carbon::parse($value->date)->format('d M')),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}