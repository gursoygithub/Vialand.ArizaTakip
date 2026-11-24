<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TeamTicketResource;
use App\Models\Ticket;
use App\Models\TicketSlaCycle;
use App\Services\TicketWorkflowService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard headline for the team model. Every number is limited to the
 * requests the viewer may see (Ticket::scopeVisibleInTeamWorkflow).
 */
class RequestOverviewStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    public const ACTIVE = [
        TaskStatusEnum::OPEN,
        TaskStatusEnum::ASSIGNED,
        TaskStatusEnum::IN_PROGRESS,
        TaskStatusEnum::ON_HOLD,
    ];

    public static function canView(): bool
    {
        return auth()->user()?->can('widget_RequestOverviewStats') ?? false;
    }

    protected function getHeading(): ?string
    {
        return __('ui.dashboard_heading');
    }

    private function visible(): Builder
    {
        return Ticket::query()->visibleInTeamWorkflow(auth()->user());
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $employeeIds = $user?->employeeIds() ?? [];
        $url = TeamTicketResource::getUrl('index');

        $active = $this->visible()->whereIn('status', self::ACTIVE);
        $activeCount = (clone $active)->count();
        $pool = (clone $active)->where('status', TaskStatusEnum::OPEN)->whereNull('employee_id')->count();

        $mine = $employeeIds !== [] ? (clone $active)->whereIn('employee_id', $employeeIds)->count() : 0;
        $mineInProgress = $employeeIds !== [] ? (clone $active)->whereIn('employee_id', $employeeIds)->where('status', TaskStatusEnum::IN_PROGRESS)->count() : 0;

        [$breached, $atRisk] = $this->slaRisk();

        $awaiting = $this->visible()->where('status', TaskStatusEnum::RESOLVED)->where('created_by', $user?->id)->count();

        [$compliance, $onTime, $sealed, $satisfaction, $rated] = $this->thisMonth();

        return [
            Stat::make(__('ui.dashboard_open'), $activeCount)
                ->description(__('ui.dashboard_open_desc', ['pool' => $pool]))
                ->descriptionIcon('heroicon-o-inbox-stack')
                ->color('primary')
                ->url($url),

            Stat::make(__('ui.dashboard_mine'), $mine)
                ->description(__('ui.dashboard_mine_desc', ['count' => $mineInProgress]))
                ->descriptionIcon('heroicon-o-user')
                ->color($mine > 0 ? 'warning' : 'gray')
                ->url($url),

            Stat::make(__('ui.dashboard_sla'), $breached)
                ->description(__('ui.dashboard_sla_desc', ['count' => $atRisk]))
                ->descriptionIcon('heroicon-o-fire')
                ->color($breached > 0 ? 'danger' : ($atRisk > 0 ? 'warning' : 'success')),

            Stat::make(__('ui.dashboard_awaiting'), $awaiting)
                ->description(__('ui.dashboard_awaiting_desc'))
                ->descriptionIcon('heroicon-o-hand-thumb-up')
                ->color($awaiting > 0 ? 'warning' : 'gray'),

            Stat::make(__('ui.dashboard_compliance'), $compliance === null ? '—' : '%'.number_format($compliance, 1, ',', '.'))
                ->description(__('ui.dashboard_compliance_desc', ['on' => $onTime, 'total' => $sealed]))
                ->descriptionIcon('heroicon-o-chart-bar')
                ->color($compliance === null ? 'gray' : ($compliance >= 90 ? 'success' : ($compliance >= 75 ? 'warning' : 'danger'))),

            Stat::make(__('ui.dashboard_satisfaction'), $satisfaction === null ? '—' : number_format($satisfaction, 1, ',', '.').' / 5')
                ->description(__('ui.dashboard_satisfaction_desc', ['count' => $rated]))
                ->descriptionIcon('heroicon-o-star')
                ->color('gray'),
        ];
    }

    /**
     * Open requests already past the deadline, and those past 80 %.
     *
     * @return array{0: int, 1: int}
     */
    private function slaRisk(): array
    {
        $cycles = TicketSlaCycle::query()
            ->running()
            ->whereIn('ticket_id', $this->visible()->whereIn('status', self::ACTIVE)->select('tickets.id'))
            ->with('workCalendar')
            ->limit(1000)
            ->get();

        $workflow = app(TicketWorkflowService::class);
        $breached = $cycles->filter(fn (TicketSlaCycle $c): bool => $c->resolution_breached || (! $c->isPaused() && $c->resolution_due_at->isPast()))->count();
        $atRisk = $cycles->filter(fn (TicketSlaCycle $c): bool => ! $c->resolution_breached && ! $c->isPaused()
            && $c->resolution_due_at->isFuture()
            && $workflow->elapsedRatio($c) >= TicketWorkflowService::WARNING_RATIO)->count();

        return [$breached, $atRisk];
    }

    /**
     * Compliance and satisfaction of the visible requests sealed this month.
     *
     * @return array{0: float|null, 1: int, 2: int, 3: float|null, 4: int}
     */
    private function thisMonth(): array
    {
        $cycles = TicketSlaCycle::query()
            ->countsForPerformance()
            ->where('ended_at', '>=', now()->startOfMonth())
            ->whereIn('ticket_id', $this->visible()->select('tickets.id'))
            ->get(['outcome', 'satisfaction_score']);

        $sealed = $cycles->count();
        $onTime = $cycles->filter(fn (TicketSlaCycle $c): bool => $c->outcome?->value === 'on_time')->count();
        $scores = $cycles->pluck('satisfaction_score')->filter();

        return [
            $sealed > 0 ? round($onTime / $sealed * 100, 1) : null,
            $onTime,
            $sealed,
            $scores->isNotEmpty() ? round($scores->avg(), 1) : null,
            $scores->count(),
        ];
    }
}
