<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TicketStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    public function getHeading(): ?string
    {
        return __('ui.widget_ticket_stats_heading');
    }

    protected function getStats(): array
    {
        $openStatuses = [
            TaskStatusEnum::OPEN->value,
            TaskStatusEnum::ASSIGNED->value,
            TaskStatusEnum::IN_PROGRESS->value,
        ];

        $closedStatuses = [
            TaskStatusEnum::CLOSED->value,
            TaskStatusEnum::CANCELLED->value,
            TaskStatusEnum::COMPLETED->value,
        ];

        $openCount = Ticket::query()
            ->visibleBy(auth()->user())
            ->whereIn('status', $openStatuses)
            ->count();

        // Live "currently breaching" — uses the scope's now()-comparison so
        // it reflects reality even when the CheckSlaBreaches job is behind.
        // The `sla_breached` column is reserved for historical compliance
        // metrics below.
        $breachedCount = Ticket::query()
            ->visibleBy(auth()->user())
            ->slaBreached()
            ->count();

        $resolvedToday = Ticket::query()
            ->visibleBy(auth()->user())
            ->where('status', TaskStatusEnum::RESOLVED->value)
            ->whereDate('resolved_at', today())
            ->count();

        $resolvedThisMonth = Ticket::query()
            ->visibleBy(auth()->user())
            ->where('status', TaskStatusEnum::RESOLVED->value)
            ->whereBetween('resolved_at', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ])
            ->count();

        $thirtyDaysAgo = now()->subDays(30);
        $totalClosedRecent = Ticket::query()
            ->visibleBy(auth()->user())
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $thirtyDaysAgo)
            ->count();
        $onTimeRecent = Ticket::query()
            ->visibleBy(auth()->user())
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $thirtyDaysAgo)
            ->where('sla_breached', false)
            ->count();

        $compliance = $totalClosedRecent > 0
            ? ($onTimeRecent / $totalClosedRecent) * 100
            : null;

        $complianceColor = match (true) {
            $compliance === null   => 'gray',
            $compliance >= 80      => 'success',
            $compliance >= 60      => 'warning',
            default                => 'danger',
        };

        $complianceLabel = $compliance === null
            ? '—'
            : '%' . number_format($compliance, 1, ',', '.');

        $complianceDesc = $compliance === null
            ? __('ui.widget_ticket_stats_sla_compliance_no_data')
            : __('ui.widget_ticket_stats_sla_compliance_desc', [
                'onTime' => number_format($onTimeRecent),
                'total'  => number_format($totalClosedRecent),
            ]);

        return [
            Stat::make(__('ui.widget_ticket_stats_open_tickets'), number_format($openCount))
                ->description(__('ui.widget_ticket_stats_open_tickets_desc'))
                ->descriptionIcon('heroicon-o-ticket')
                ->color('primary')
                ->icon('heroicon-o-ticket'),

            Stat::make(__('ui.widget_ticket_stats_sla_breached'), number_format($breachedCount))
                ->description($breachedCount > 0 ? __('ui.widget_ticket_stats_sla_breached_desc_active') : __('ui.widget_ticket_stats_sla_breached_desc_clear'))
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($breachedCount > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),

            Stat::make(__('ui.widget_ticket_stats_resolved_today'), number_format($resolvedToday))
                ->description(__('ui.widget_ticket_stats_resolved_today_desc'))
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success')
                ->icon('heroicon-o-check-circle'),

            Stat::make(__('ui.widget_ticket_stats_resolved_this_month'), number_format($resolvedThisMonth))
                ->description(__('ui.widget_ticket_stats_resolved_this_month_desc'))
                ->descriptionIcon('heroicon-o-calendar')
                ->color('info')
                ->icon('heroicon-o-calendar'),

            Stat::make(__('ui.widget_ticket_stats_sla_compliance'), $complianceLabel)
                ->description($complianceDesc)
                ->descriptionIcon('heroicon-o-chart-bar')
                ->color($complianceColor)
                ->icon('heroicon-o-chart-bar'),
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->hasRole('super_admin')
            || $user->can('widget_TicketStatsOverview');
    }
}
