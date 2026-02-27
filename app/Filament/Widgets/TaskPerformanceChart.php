<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Support\Carbon;

class TaskPerformanceChart extends ChartWidget
{
    protected static ?string $heading = 'Bu Ayın SLA Performansı';
    protected static string $color = 'success';

    // Grafiğin dikeyde devleşmesini önler, ekranı dengeler
    protected static ?string $maxHeight = '250px';
    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $monthStart = now()->startOfMonth();
        $today = now();

        // Zamanında Bitirilenler (SUCCESS)
        $successData = Trend::query(Task::where('sla_outcome', 'SUCCESS'))
            ->between(start: $monthStart, end: $today)
            ->perDay()
            ->count();

        // Gecikmeli Kapatılanlar (FAILED)
        $failedData = Trend::query(Task::where('sla_outcome', 'FAILED'))
            ->between(start: $monthStart, end: $today)
            ->perDay()
            ->count();

        return [
            'datasets' => [
                [
                    'label' => 'Zamanında',
                    'data' => $successData->map(fn (TrendValue $value) => $value->aggregate),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => 'start',
                    'tension' => 0.4,
                ],
                [
                    'label' => 'Gecikmeli',
                    'data' => $failedData->map(fn (TrendValue $value) => $value->aggregate),
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => 'start',
                    'tension' => 0.4,
                ],
            ],
            // Tarihleri Türkçe "Gün Ay" (Örn: 27 Şub) formatına çevirdik
            'labels' => $successData->map(fn (TrendValue $value) =>
            Carbon::parse($value->date)->translatedFormat('d M')
            ),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => false, // Gereksiz yer kaplamasın
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0], // Sadece tam sayılar (0, 1, 2 görev gibi)
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}