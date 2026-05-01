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
        return 'Talep Genel Bakış';
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
            ->whereDate('closed_at', '>=', now()->startOfDay())
            ->count();

        $thirtyDaysAgo = now()->subDays(30);
        $totalClosedRecent = Ticket::query()
            ->visibleBy(auth()->user())
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $thirtyDaysAgo)
            ->count();
        $onTimeRecent = Ticket::query()
            ->visibleBy(auth()->user())
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $thirtyDaysAgo)
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
            : number_format($compliance, 1, ',', '.') . '%';

        $complianceDesc = $compliance === null
            ? 'Henüz kapatılan talep yok'
            : number_format($onTimeRecent) . ' / ' . number_format($totalClosedRecent) . ' zamanında';

        return [
            Stat::make('Açık Talepler', number_format($openCount))
                ->description('Bekleyen iş yükü')
                ->descriptionIcon('heroicon-o-ticket')
                ->color('primary')
                ->icon('heroicon-o-ticket'),

            Stat::make('SLA İhlali', number_format($breachedCount))
                ->description($breachedCount > 0 ? 'Acil müdahale gerekli' : 'Tüm talepler süresinde')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($breachedCount > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),

            Stat::make('Bugün Çözülen', number_format($resolvedToday))
                ->description('Son 24 saat içinde kapatıldı')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success')
                ->icon('heroicon-o-check-circle'),

            Stat::make('SLA Uyum Oranı', $complianceLabel)
                ->description($complianceDesc . ' (son 30 gün)')
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
