<?php

namespace App\Services;

use App\Enums\TaskStatusEnum;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PerformanceService
{
    /**
     * Per-user SLA & resolution statistics.
     *
     * Returns 8 metrics:
     *   total_assigned, closed_on_time, closed_breached, currently_open,
     *   currently_on_hold, avg_resolution_minutes, sla_compliance_rate,
     *   avg_response_time_minutes
     *
     * Visibility: scoped by the *viewer's* permissions (Ticket::scopeVisibleBy).
     * A supervisor querying stats for a tech outside their region gets zeroes.
     */
    public function getStats(User $user, Carbon $from, Carbon $to, ?User $viewer = null): array
    {
        $employee = Employee::where('email', $user->email)->first();

        if (!$employee) {
            return $this->emptyStats($user);
        }

        $viewer ??= auth()->user();

        $tickets = Ticket::query()
            ->visibleBy($viewer)
            ->where('employee_id', $employee->id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        return $this->aggregate($user, $tickets);
    }

    /**
     * Per-person stats for everyone in an area.
     */
    public function getTeamStats(int $areaId, Carbon $from, Carbon $to, ?User $viewer = null): Collection
    {
        $viewer ??= auth()->user();

        $employees = \App\Models\GroupMember::whereHas('group', fn ($q) => $q->where('area_id', $areaId))
            ->with('employee.user')
            ->get()
            ->pluck('employee')
            ->filter()
            ->unique('id');

        return $employees->map(function (Employee $employee) use ($from, $to, $viewer) {
            $u = $employee->user;
            return $u ? $this->getStats($u, $from, $to, $viewer) : null;
        })->filter()->values();
    }

    /**
     * Dashboard overview totals — same metrics aggregated across visible tickets.
     */
    public function getOverview(Carbon $from, Carbon $to, ?User $viewer = null): array
    {
        $viewer ??= auth()->user();

        $tickets = Ticket::query()
            ->visibleBy($viewer)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        return $this->aggregate(null, $tickets);
    }

    /**
     * Region/unit breakdown — counts grouped by area.
     */
    public function getRegionBreakdown(Carbon $from, Carbon $to, ?User $viewer = null): Collection
    {
        $viewer ??= auth()->user();

        return Ticket::query()
            ->visibleBy($viewer)
            ->whereBetween('created_at', [$from, $to])
            ->with('area:id,name')
            ->get()
            ->groupBy('area_id')
            ->map(function ($group) {
                $first   = $group->first();
                $closed  = $group->filter(fn ($t) => $t->closed_at !== null);
                $onTime  = $closed->filter(fn ($t) => $t->sla_deadline && $t->closed_at?->lte($t->sla_deadline))->count();

                return [
                    'area_name'  => $first->area?->name ?? '—',
                    'total'      => $group->count(),
                    'closed'     => $closed->count(),
                    'on_time'    => $onTime,
                    'breached'   => $group->where('sla_breached', true)->count(),
                    'compliance' => $closed->count() > 0 ? round(($onTime / $closed->count()) * 100, 1) : 0,
                ];
            })
            ->values();
    }

    /**
     * Aggregate a Ticket collection into the 8 metrics. Excludes cancelled.
     */
    private function aggregate(?User $user, $tickets): array
    {
        $tickets = collect($tickets)->reject(fn ($t) => $t->status === TaskStatusEnum::CANCELLED);

        $closed       = $tickets->filter(fn ($t) => $t->closed_at !== null);
        $onTime       = $closed->filter(fn ($t) =>
            $t->sla_deadline && $t->closed_at?->lte($t->sla_deadline)
        );
        $breached     = $tickets->where('sla_breached', true);
        $currentlyOpen = $tickets->whereIn('status', [
            TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS,
        ])->count();
        $onHold       = $tickets->where('status', TaskStatusEnum::ON_HOLD)->count();

        $avgResolution = $closed->filter(fn ($t) => $t->assigned_at)
            ->map(fn ($t) => $t->assigned_at->diffInMinutes($t->closed_at))
            ->avg() ?? 0;

        $avgResponse = $tickets->filter(fn ($t) => $t->assigned_at)
            ->map(fn ($t) => $t->created_at->diffInMinutes($t->assigned_at))
            ->avg() ?? 0;

        $compliance = $closed->count() > 0
            ? round(($onTime->count() / $closed->count()) * 100, 1)
            : 0;

        $base = [
            'total_assigned'           => $tickets->count(),
            'closed_on_time'           => $onTime->count(),
            'closed_breached'          => $breached->count(),
            'currently_open'           => $currentlyOpen,
            'currently_on_hold'        => $onHold,
            'avg_resolution_minutes'   => (int) round($avgResolution),
            'sla_compliance_rate'      => $compliance,
            'avg_response_time_minutes' => (int) round($avgResponse),
            // Backward compat keys used by older blades:
            'total'                    => $tickets->count(),
            'closed'                   => $closed->count(),
            'on_time'                  => $onTime->count(),
            'breach_count'             => $breached->count(),
            'compliance_rate'          => $compliance,
            'open'                     => $currentlyOpen,
            'breached'                 => $breached->count(),
        ];

        if ($user) {
            $base['user'] = $user;
        }

        return $base;
    }

    private function emptyStats(User $user): array
    {
        return [
            'user'                      => $user,
            'total_assigned'            => 0,
            'closed_on_time'            => 0,
            'closed_breached'           => 0,
            'currently_open'            => 0,
            'currently_on_hold'         => 0,
            'avg_resolution_minutes'    => 0,
            'sla_compliance_rate'       => 0,
            'avg_response_time_minutes' => 0,
            // Backward compat
            'total'                     => 0,
            'closed'                    => 0,
            'on_time'                   => 0,
            'breach_count'              => 0,
            'compliance_rate'           => 0,
        ];
    }
}
