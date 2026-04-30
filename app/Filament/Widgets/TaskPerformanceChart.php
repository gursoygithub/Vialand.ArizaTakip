<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

class TaskPerformanceChart extends ChartWidget
{
    public function getHeading(): ?string
    {
        return __('ui.resolved_task_performance_for_current_month');
    }

//    public function getDescription(): string|Htmlable|null
//    {
//        return __('ui.performance_chart_description');
//    }

    protected static string $color = 'success';
    protected static ?string $maxHeight = '250px';
    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 888; // Dashboard'da sıralama için

    protected function getData(): array
    {
        $user = auth()->user();
        // Referans koddaki yetki mantığı: Admin mi yoksa tüm işleri görme yetkisi var mı?
        $hasPermission = $user->hasRole('super_admin') || $user->can('view_all_tasks');

        $monthStart = now()->startOfMonth();
        $today = now();

        // Ortak sorgu yapısını yetkiye göre hazırlıyoruz
        $baseQuery = Task::query()
            ->when(!$hasPermission, function (Builder $query) use ($user) {
                // Sadece kendi açtığı veya kendi üzerine atanan işler
                $query->where(function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                        ->orWhere('employee_id', function ($sub) use ($user) {
                            $sub->select('id')->from('employees')->where('email', $user->email);
                        });
                });
            });

        // Zamanında Bitirilenler (SUCCESS) - Klonlayarak ana sorguyu bozmuyoruz
        $successData = Trend::query((clone $baseQuery)->where('sla_outcome', 'SUCCESS'))
            ->between(start: $monthStart, end: $today)
            ->perDay()
            ->count();

        // Gecikmeli Kapatılanlar (FAILED)
        $failedData = Trend::query((clone $baseQuery)->where('sla_outcome', 'FAILED'))
            ->between(start: $monthStart, end: $today)
            ->perDay()
            ->count();

        return [
            'datasets' => [
                [
                    'label' => __('ui.on_time'),
                    'data' => $successData->map(fn (TrendValue $value) => $value->aggregate),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => 'start',
                    'tension' => 0.4,
                ],
                [
                    'label' => __('ui.delayed'),
                    'data' => $failedData->map(fn (TrendValue $value) => $value->aggregate),
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => 'start',
                    'tension' => 0.4,
                ],
            ],
            'labels' => $successData->map(fn (TrendValue $value) =>
            Carbon::parse($value->date)->translatedFormat('d M')
            ),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }

    protected function getType(): string { return 'line'; }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_TaskPerformanceChart');
    }
}