<?php

namespace App\Services;

use App\Enums\BreachAttributionEnum;
use App\Enums\SlaCycleOutcomeEnum;
use App\Models\Employee;
use App\Models\Team;
use App\Models\TicketSlaCycle;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Performance figures of the team model (agreement §6). Built only from the
 * immutable cycle / assignment records:
 *
 *  - Only sealed cycles (on time / breached) without an approved exemption
 *    count. Cancelled, rejected, transferred and exempted cycles never do.
 *  - A person is charged only for breaches attributed to them (`person`);
 *    handover and dispatch breaches stay on the team.
 *  - The resolver is whoever held the ticket last in the cycle. Reopens and
 *    rejected resolutions are quality figures of the resolver.
 *  - Cycles are picked by the moment they ended, inside [$from, $to].
 */
class TeamPerformanceService
{
    public function __construct(private WorkingTimeCalculator $calculator) {}

    /**
     * Teams the viewer may report on: all for super_admin / ticket.view.all,
     * otherwise the teams they supervise.
     *
     * @return Collection<int, Team>
     */
    public function teamsVisibleTo(User $viewer): Collection
    {
        $query = Team::query()->with('ticketType')->orderBy('name');

        if ($viewer->hasRole('super_admin') || $viewer->can('ticket.view.all')) {
            return $query->get();
        }

        $employeeId = $viewer->employee?->id;

        return $employeeId ? $query->where('supervisor_employee_id', $employeeId)->get() : collect();
    }

    /**
     * @param  array{team_ids?: array<int, int>, ticket_type_id?: int|null}  $filters
     * @return array{summary: array<string, mixed>, teams: array<int, array<string, mixed>>, people: array<int, array<string, mixed>>, types: array<int, array<string, mixed>>, companies: array<int, array<string, mixed>>}
     */
    public function report(CarbonInterface $from, CarbonInterface $to, User $viewer, array $filters = []): array
    {
        $teamIds = $this->teamsVisibleTo($viewer)->pluck('id');

        if (! empty($filters['team_ids'])) {
            $teamIds = $teamIds->intersect($filters['team_ids']);
        }

        $cycles = TicketSlaCycle::query()
            ->whereNotNull('ended_at')
            ->whereBetween('ended_at', [$from, $to])
            ->whereIn('team_id', $teamIds)
            ->when($filters['ticket_type_id'] ?? null, fn (Builder $q, $typeId) => $q->whereHas('team', fn (Builder $t) => $t->where('ticket_type_id', $typeId)))
            ->with(['team.ticketType', 'workCalendar', 'assignments.employee.company', 'breachEmployee.company'])
            ->get();

        $rows = $cycles->map(fn (TicketSlaCycle $cycle): array => $this->describe($cycle, $cycles));
        $counted = $rows->where('counts', true);

        return [
            'summary' => $this->aggregate($rows) + [
                'teams' => $counted->pluck('team_id')->unique()->count(),
            ],
            'teams' => $rows->groupBy('team_id')
                ->map(fn (Collection $group): array => [
                    'name' => $group->first()['team_name'],
                    'type' => $group->first()['type_name'],
                ] + $this->aggregate($group))
                ->sortBy('name')->values()->all(),
            'types' => $rows->groupBy('type_name')
                ->map(fn (Collection $group, string $type): array => ['name' => $type] + $this->aggregate($group))
                ->sortBy('name')->values()->all(),
            'people' => $this->people($rows),
            'companies' => $this->companies($rows),
        ];
    }

    /**
     * One flat record per cycle with everything the aggregations need.
     *
     * @param  Collection<int, TicketSlaCycle>  $all
     * @return array<string, mixed>
     */
    private function describe(TicketSlaCycle $cycle, Collection $all): array
    {
        $counts = $cycle->outcome?->countsForPerformance() && ! $cycle->exempted;
        $breached = $counts && $cycle->outcome === SlaCycleOutcomeEnum::Breached;
        $resolver = $cycle->assignments->sortBy('started_at')->last()?->employee;

        $reopened = $counts && TicketSlaCycle::query()
            ->where('ticket_id', $cycle->ticket_id)
            ->where('cycle_no', $cycle->cycle_no + 1)
            ->where('team_id', $cycle->team_id)
            ->exists();

        $resolutionMinutes = $counts && $cycle->resolved_at
            ? max(0, $this->calculator->workingMinutesBetween($cycle->started_at, $cycle->resolved_at, $cycle->workCalendar) - $cycle->paused_minutes)
            : null;

        return [
            'team_id' => $cycle->team_id,
            'team_name' => $cycle->team?->name ?? '—',
            'type_name' => $cycle->team?->ticketType?->name ?? '—',
            'counts' => $counts,
            'breached' => $breached,
            'response_breached' => $counts && $cycle->response_breached,
            'attribution' => $breached ? $cycle->breach_attribution : null,
            'charged' => $breached && $cycle->breach_attribution === BreachAttributionEnum::Person ? $cycle->breachEmployee : null,
            'resolver' => $counts ? $resolver : null,
            'exempted' => $cycle->exempted,
            'cancelled_after_breach' => $cycle->outcome === SlaCycleOutcomeEnum::Cancelled && $cycle->resolution_breached,
            'reopened' => $reopened,
            'rejections' => $counts ? $cycle->resolution_rejections : 0,
            'satisfaction' => $counts ? $cycle->satisfaction_score : null,
            'resolution_minutes' => $resolutionMinutes,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregate(Collection $rows): array
    {
        $counted = $rows->where('counts', true);
        $total = $counted->count();
        $breached = $counted->where('breached', true)->count();
        $scores = $counted->pluck('satisfaction')->filter();
        $minutes = $counted->pluck('resolution_minutes')->filter(fn ($m) => $m !== null);

        return [
            'total' => $total,
            'on_time' => $total - $breached,
            'breached' => $breached,
            'compliance' => $total > 0 ? round(($total - $breached) / $total * 100, 1) : null,
            'response_breached' => $counted->where('response_breached', true)->count(),
            'personal_breaches' => $counted->where('attribution', BreachAttributionEnum::Person)->count(),
            'handover_breaches' => $counted->where('attribution', BreachAttributionEnum::Handover)->count(),
            'dispatch_delays' => $counted->where('attribution', BreachAttributionEnum::Dispatch)->count(),
            'exempted' => $rows->where('exempted', true)->count(),
            'cancelled_after_breach' => $rows->where('cancelled_after_breach', true)->count(),
            'reopened' => $counted->where('reopened', true)->count(),
            'reopen_rate' => $total > 0 ? round($counted->where('reopened', true)->count() / $total * 100, 1) : null,
            'rejections' => $counted->sum('rejections'),
            'satisfaction' => $scores->isNotEmpty() ? round($scores->avg(), 2) : null,
            'rated' => $scores->count(),
            'avg_resolution_minutes' => $minutes->isNotEmpty() ? (int) round($minutes->avg()) : null,
        ];
    }

    /**
     * Personal score: cycles the person resolved plus any breach charged to
     * them (a breach can sit with someone who handed over later).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function people(Collection $rows): array
    {
        $people = [];

        foreach ($rows->where('counts', true) as $index => $row) {
            foreach (array_filter([$row['resolver'], $row['charged']]) as $employee) {
                /** @var Employee $employee */
                $people[$employee->id] ??= ['employee' => $employee, 'cycles' => [], 'resolved' => [], 'charged' => 0];
                $people[$employee->id]['cycles'][$index] = true;
            }

            if ($row['resolver']) {
                $people[$row['resolver']->id]['resolved'][] = $row;
            }

            if ($row['charged']) {
                $people[$row['charged']->id]['charged']++;
            }
        }

        return collect($people)
            ->map(function (array $person): array {
                $total = count($person['cycles']);
                $resolved = collect($person['resolved']);
                $scores = $resolved->pluck('satisfaction')->filter();
                $minutes = $resolved->pluck('resolution_minutes')->filter(fn ($m) => $m !== null);

                return [
                    'name' => $person['employee']->name,
                    'company' => $person['employee']->company?->name ?? $person['employee']->company_name,
                    'total' => $total,
                    'resolved' => $resolved->count(),
                    'personal_breaches' => $person['charged'],
                    'compliance' => $total > 0 ? round(($total - $person['charged']) / $total * 100, 1) : null,
                    'reopened' => $resolved->where('reopened', true)->count(),
                    'rejections' => $resolved->sum('rejections'),
                    'satisfaction' => $scores->isNotEmpty() ? round($scores->avg(), 2) : null,
                    'avg_resolution_minutes' => $minutes->isNotEmpty() ? (int) round($minutes->avg()) : null,
                ];
            })
            ->sortBy([['compliance', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Per company of the people who did the work.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function companies(Collection $rows): array
    {
        return collect($this->people($rows))
            ->groupBy(fn (array $person): string => $person['company'] ?? '—')
            ->map(function (Collection $group, string $company): array {
                $total = $group->sum('total');
                $charged = $group->sum('personal_breaches');

                return [
                    'name' => $company,
                    'people' => $group->count(),
                    'total' => $total,
                    'personal_breaches' => $charged,
                    'compliance' => $total > 0 ? round(($total - $charged) / $total * 100, 1) : null,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }
}
