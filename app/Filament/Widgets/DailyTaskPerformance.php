<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class DailyTaskPerformance extends ChartWidget
{
    protected static ?string $heading = null; // getHeading() içinden yönetiyoruz
    protected static ?string $maxHeight = '300px';
    protected int | string | array $columnSpan = 'full';

    // Filtreleme ekleyerek grafiği daha işlevsel hale getirebiliriz
    public ?string $filter = 'month';

    protected static ?int $sort = 777; // Dashboard'da sıralama için

    public function getHeading(): ?string
    {
        return __('ui.daily_task_performance');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('ui.daily_task_performance_description');
    }

    protected function getData(): array
    {
        $user = auth()->user();
        $hasPermission = $user->hasRole('super_admin') || $user->can('view_all_tasks');

        // Zaman Aralığı Belirleme
        $start = now()->startOfMonth();
        $end = now();

        // Temel Sorgu (Scope mantığı ile temizlendi)
        $baseQuery = Task::query()
            ->when(!$hasPermission, function (Builder $query) use ($user) {
                $query->where(function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                        ->orWhereHas('employee', fn($e) => $e->where('email', $user->email));
                });
            });

        // Verileri Çekme
        $successTrend = Trend::query((clone $baseQuery)->where('sla_outcome', 'SUCCESS'))
            ->between(start: $start, end: $end)
            ->perDay()
            ->count();

        $failedTrend = Trend::query((clone $baseQuery)->where('sla_outcome', 'FAILED'))
            ->between(start: $start, end: $end)
            ->perDay()
            ->count();

        return [
            'datasets' => [
                [
                    'label' => __('ui.on_time'),
                    'data' => $successTrend->map(fn (TrendValue $value) => $value->aggregate),
                    'backgroundColor' => '#10b981', // Emerald 500
                    'borderRadius' => 4,
                ],
                [
                    'label' => __('ui.delayed'),
                    'data' => $failedTrend->map(fn (TrendValue $value) => $value->aggregate),
                    'backgroundColor' => '#ef4444', // Red 500
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $successTrend->map(fn (TrendValue $value) => Carbon::parse($value->date)->translatedFormat('d M')),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
            ],
            'scales' => [
                'x' => [
                    'stacked' => true, // Sütunları üst üste bindirir
                    'grid' => ['display' => false],
                ],
                'y' => [
                    'stacked' => true, // Toplam iş hacmini gösterir
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
            'elements' => [
                'bar' => [
                    'borderWidth' => 0,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_DailyTaskPerformance');
    }
}
