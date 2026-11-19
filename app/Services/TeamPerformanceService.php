<?php

namespace App\Services;

use App\Enums\BreachAttributionEnum;
use App\Enums\SlaCycleOutcomeEnum;
use App\Enums\TaskStatusEnum;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketHold;
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
    /**
     * Statuses in which a request is still on its holder's plate.
     *
     * @var array<int, TaskStatusEnum>
     */
    public const ACTIVE_STATUSES = [
        TaskStatusEnum::OPEN,
        TaskStatusEnum::ASSIGNED,
        TaskStatusEnum::IN_PROGRESS,
        TaskStatusEnum::ON_HOLD,
    ];

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
     * One person's figures across every team they worked in, by the same
     * rules as the report: only cycles they resolved or whose breach was
     * charged to them, and only breaches attributed to them count against
     * them.
     *
     * @return array{summary: array<string, mixed>, last30: array<string, mixed>, teams: array<int, array<string, mixed>>, priorities: array<int, array<string, mixed>>, holds: array<string, mixed>, holds_last30: array<string, mixed>}
     */
    public function personReport(Employee $employee): array
    {
        $cycles = TicketSlaCycle::query()
            ->whereNotNull('ended_at')
            ->where(fn (Builder $q) => $q->where('breach_employee_id', $employee->id)
                ->orWhereHas('assignments', fn (Builder $a) => $a->where('employee_id', $employee->id)))
            ->with(['team.ticketType', 'workCalendar', 'assignments.employee', 'breachEmployee'])
            ->get();

        $rows = $cycles
            ->map(fn (TicketSlaCycle $cycle): array => $this->describe($cycle, $cycles))
            ->filter(fn (array $row): bool => $row['counts']
                && ($row['resolver']?->id === $employee->id || $row['charged']?->id === $employee->id))
            ->values();

        $since = now()->subDays(30);

        return [
            'summary' => $this->personFigures($rows, $employee),
            'last30' => $this->personFigures($rows->filter(fn (array $row): bool => $row['ended_at'] >= $since), $employee),
            'teams' => $rows->groupBy('team_id')
                ->map(fn (Collection $group): array => [
                    'name' => $group->first()['team_name'],
                    'type' => $group->first()['type_name'],
                ] + $this->personFigures($group, $employee))
                ->sortBy('name')->values()->all(),
            'priorities' => $rows->groupBy(fn (array $row): int => $row['priority']->value)
                ->sortKeysDesc()
                ->map(fn (Collection $group): array => [
                    'priority' => $group->first()['priority'],
                ] + $this->personFigures($group, $employee))
                ->values()->all(),
            'holds' => $this->holdFigures($employee),
            'holds_last30' => $this->holdFigures($employee, $since),
        ];
    }

    /**
     * Holds the person started: how often, how long in working minutes (an
     * open hold counts up to now), how many outlasted the team's alert
     * limit, and the split by reason.
     *
     * @return array{count: int, open: int, minutes: int, overdue: int, reasons: array<string, int>}
     */
    public function holdFigures(Employee $employee, ?CarbonInterface $since = null): array
    {
        $holds = TicketHold::query()
            ->where('employee_id', $employee->id)
            ->when($since, fn (Builder $q) => $q->where('started_at', '>=', $since))
            ->with('team.workCalendar')
            ->get();

        $now = now();

        return [
            'count' => $holds->count(),
            'open' => $holds->whereNull('ended_at')->count(),
            'minutes' => (int) $holds->sum(fn (TicketHold $hold): int => $hold->working_minutes
                ?? $this->calculator->workingMinutesBetween($hold->started_at, $now, $hold->team?->workCalendar)),
            'overdue' => $holds->whereNotNull('alerted_at')->count(),
            'reasons' => $holds->groupBy(fn (TicketHold $hold): string => $hold->reason->getLabel())
                ->map->count()->sortDesc()->all(),
        ];
    }

    /**
     * Requests on the person's plate right now (team model only).
     *
     * @return array{active: int, breached: int}
     */
    public function currentWorkload(Employee $employee): array
    {
        $query = Ticket::query()
            ->whereNotNull('team_id')
            ->where('employee_id', $employee->id)
            ->whereIn('status', self::ACTIVE_STATUSES);

        return [
            'active' => (clone $query)->count(),
            'breached' => (clone $query)->where('sla_breached', true)->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  counted rows involving the person
     * @return array<string, mixed>
     */
    private function personFigures(Collection $rows, Employee $employee): array
    {
        $total = $rows->count();
        $charged = $rows->filter(fn (array $row): bool => $row['charged']?->id === $employee->id)->count();
        $resolved = $rows->filter(fn (array $row): bool => $row['resolver']?->id === $employee->id);
        $scores = $resolved->pluck('satisfaction')->filter();
        $minutes = $resolved->pluck('resolution_minutes')->filter(fn ($m) => $m !== null);

        return [
            'total' => $total,
            'on_time' => $total - $charged,
            'personal_breaches' => $charged,
            'compliance' => $total > 0 ? round(($total - $charged) / $total * 100, 1) : null,
            'target' => $total > 0 ? round($rows->avg('threshold'), 1) : null,
            'resolved' => $resolved->count(),
            'reopened' => $resolved->where('reopened', true)->count(),
            'rejections' => $resolved->sum('rejections'),
            'satisfaction' => $scores->isNotEmpty() ? round($scores->avg(), 2) : null,
            'rated' => $scores->count(),
            'avg_resolution_minutes' => $minutes->isNotEmpty() ? (int) round($minutes->avg()) : null,
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
        $resolver = $cycle->resolved_at ? $cycle->assignments->sortBy('started_at')->last()?->employee : null;

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
            'priority' => $cycle->priority,
            'threshold' => $cycle->success_threshold,
            'ended_at' => $cycle->ended_at,
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
