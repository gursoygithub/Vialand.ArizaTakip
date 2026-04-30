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
     * Get SLA performance stats for a single user within a date range.
     */
    public function getStats(User $user, Carbon $from, Carbon $to): array
    {
        $employee = Employee::where('email', $user->email)->first();

        if (!$employee) {
            return $this->emptyStats($user);
        }

        $tickets = Ticket::where('employee_id', $employee->id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $total  = $tickets->count();
        $closed = $tickets->filter(fn ($t) => $t->closed_at !== null);
        $onTime = $closed->filter(fn ($t) =>
            $t->sla_deadline && $t->closed_at->lte($t->sla_deadline)
        )->count();

        $breachCount    = $tickets->where('sla_breached', true)->count();
        $complianceRate = $closed->count() > 0 ? round(($onTime / $closed->count()) * 100, 1) : 0;

        $avgResolutionMinutes = $closed->filter(fn ($t) => $t->assigned_at)
            ->map(fn ($t) => $t->assigned_at->diffInMinutes($t->closed_at))
            ->avg() ?? 0;

        return [
            'user'                   => $user,
            'total'                  => $total,
            'closed'                 => $closed->count(),
            'on_time'                => $onTime,
            'breach_count'           => $breachCount,
            'compliance_rate'        => $complianceRate,
            'avg_resolution_minutes' => round($avgResolutionMinutes, 0),
        ];
    }

    /**
     * Get per-person performance for all employees in an area.
     */
    public function getTeamStats(int $areaId, Carbon $from, Carbon $to): Collection
    {
        $employeeIds = \App\Models\GroupMember::whereHas('group', fn ($q) => $q->where('area_id', $areaId))
            ->with('employee.user')
            ->get()
            ->pluck('employee')
            ->filter()
            ->unique('id');

        return $employeeIds->map(function (Employee $employee) use ($from, $to) {
            $user = $employee->user;

            if (!$user) {
                return null;
            }

            return $this->getStats($user, $from, $to);
        })->filter()->values();
    }

    /**
     * Get summary stats across all tickets (for admin dashboard overview).
     */
    public function getOverview(Carbon $from, Carbon $to): array
    {
        $tickets = Ticket::whereBetween('created_at', [$from, $to])->get();

        $openStatuses = [
            TaskStatusEnum::OPEN->value,
            TaskStatusEnum::ASSIGNED->value,
            TaskStatusEnum::IN_PROGRESS->value,
            TaskStatusEnum::ON_HOLD->value,
            TaskStatusEnum::PENDING->value,
        ];

        $open    = $tickets->whereIn('status', array_map(fn ($s) => TaskStatusEnum::from($s), $openStatuses))->count();
        $breached = $tickets->where('sla_breached', true)->count();
        $closed   = $tickets->filter(fn ($t) => $t->closed_at !== null);

        $avgResolution = $closed->filter(fn ($t) => $t->assigned_at)
            ->map(fn ($t) => $t->assigned_at->diffInMinutes($t->closed_at))
            ->avg() ?? 0;

        $onTime = $closed->filter(fn ($t) =>
            $t->sla_deadline && $t->closed_at?->lte($t->sla_deadline)
        )->count();

        $compliance = $closed->count() > 0
            ? round(($onTime / $closed->count()) * 100, 1)
            : 0;

        return [
            'total'                  => $tickets->count(),
            'open'                   => $open,
            'breached'               => $breached,
            'avg_resolution_minutes' => round($avgResolution, 0),
            'compliance_rate'        => $compliance,
        ];
    }

    private function emptyStats(User $user): array
    {
        return [
            'user'                   => $user,
            'total'                  => 0,
            'closed'                 => 0,
            'on_time'                => 0,
            'breach_count'           => 0,
            'compliance_rate'        => 0,
            'avg_resolution_minutes' => 0,
        ];
    }
}
