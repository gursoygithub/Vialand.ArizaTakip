<?php

namespace App\Services;

use App\Enums\TaskStatusEnum;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SlaService
{
    /**
     * Resolve the most specific SLA policy. 3-level fallback:
     *   1) area + unit + priority (exact match, optionally with sub_area)
     *   2) area + priority         (any unit/sub_area in that area)
     *   3) priority only           (global fallback for that priority)
     * Each match level is logged for diagnostics.
     */
    public function resolvePolicy(int $areaId, ?int $subAreaId, ?int $unitId, int|string $priority): ?SlaPolicy
    {
        // Level 1: most specific
        if ($unitId) {
            $q = SlaPolicy::where('area_id', $areaId)
                ->where('unit_id', $unitId)
                ->where('priority', $priority);

            if ($subAreaId) {
                $exact = (clone $q)->where('sub_area_id', $subAreaId)->first();
                if ($exact) {
                    Log::debug('SLA matched L1 (area+sub_area+unit+priority)', [
                        'policy_id' => $exact->id, 'area_id' => $areaId,
                    ]);
                    return $exact;
                }
            }

            $any = $q->first();
            if ($any) {
                Log::debug('SLA matched L1 (area+unit+priority)', [
                    'policy_id' => $any->id, 'area_id' => $areaId,
                ]);
                return $any;
            }
        }

        // Level 2: area + priority (any unit)
        $byArea = SlaPolicy::where('area_id', $areaId)
            ->where('priority', $priority)
            ->first();

        if ($byArea) {
            Log::debug('SLA matched L2 (area+priority)', [
                'policy_id' => $byArea->id, 'area_id' => $areaId,
            ]);
            return $byArea;
        }

        // Level 3: priority only
        $byPriority = SlaPolicy::where('priority', $priority)->first();

        if ($byPriority) {
            Log::debug('SLA matched L3 (priority only)', [
                'policy_id' => $byPriority->id, 'priority' => $priority,
            ]);
            return $byPriority;
        }

        Log::debug('SLA no match', [
            'area_id' => $areaId, 'unit_id' => $unitId, 'priority' => $priority,
        ]);

        return null;
    }

    /**
     * Initial deadline = $from + policy.deadline_minutes.
     * The on-hold extension is applied separately via extendDeadlineForOnHold().
     */
    public function calculateDeadline(SlaPolicy $policy, Carbon $from): Carbon
    {
        return $from->copy()->addMinutes($policy->deadline_minutes);
    }

    /**
     * Extend a ticket's sla_deadline by the duration it just spent on hold.
     * Returns the number of minutes added.
     *
     * `on_hold_since` is ALWAYS cleared if it was set, regardless of whether
     * an `sla_deadline` exists to extend. Without this, leaving ON_HOLD on
     * an SLA-less ticket (e.g. ON_HOLD → CANCELLED on a ticket whose
     * SlaPolicy could not be resolved at create time) would leave a stale
     * `on_hold_since` that would mislead any later live-display caller
     * (`getRemainingMinutes`, `getElapsedPercentage`) and any future
     * recalculation that consumes it.
     */
    public function extendDeadlineForOnHold(Ticket $ticket): int
    {
        if (!$ticket->on_hold_since) {
            return 0;
        }

        $minutes = $ticket->sla_deadline
            ? (int) $ticket->on_hold_since->diffInMinutes(now())
            : 0;

        if ($minutes > 0) {
            $ticket->sla_deadline          = $ticket->sla_deadline->copy()->addMinutes($minutes);
            $ticket->total_on_hold_minutes = ((int) $ticket->total_on_hold_minutes) + $minutes;
        }

        $ticket->on_hold_since = null;

        return $minutes;
    }

    /**
     * Check if a ticket has breached SLA. Cancelled tickets never breach.
     * Tickets currently on_hold are paused — not counted as breached.
     */
    public function checkBreach(Ticket $ticket): bool
    {
        if (!$ticket->sla_deadline) {
            return false;
        }

        $status = $ticket->status;
        if ($status?->isClosed() || $status === TaskStatusEnum::CANCELLED) {
            return false;
        }

        if ($status === TaskStatusEnum::ON_HOLD) {
            return false;
        }

        return now()->isAfter($ticket->sla_deadline);
    }

    /**
     * Minutes left until deadline. Negative when breached.
     * Null when no SLA policy applies. Pauses while on_hold.
     */
    public function getRemainingMinutes(Ticket $ticket): ?int
    {
        if (!$ticket->sla_deadline) {
            return null;
        }

        $reference = $ticket->status === TaskStatusEnum::ON_HOLD && $ticket->on_hold_since
            ? $ticket->on_hold_since
            : now();

        return (int) $reference->diffInMinutes($ticket->sla_deadline, false);
    }

    /**
     * Elapsed time as a fraction of the SLA window.
     * 0.0 = just created, 1.0 = exactly at deadline, >1.0 = breached.
     * Null when no SLA policy applies.
     */
    public function getElapsedPercentage(Ticket $ticket): ?float
    {
        if (!$ticket->sla_deadline || !$ticket->created_at) {
            return null;
        }

        $totalSeconds = $ticket->created_at->diffInSeconds($ticket->sla_deadline);
        if ($totalSeconds <= 0) {
            return 1.0;
        }

        $reference = $ticket->status === TaskStatusEnum::ON_HOLD && $ticket->on_hold_since
            ? $ticket->on_hold_since
            : now();

        $elapsedSeconds = $ticket->created_at->diffInSeconds($reference);

        return max(0.0, $elapsedSeconds / $totalSeconds);
    }

    /**
     * Backwards-compat alias. Deprecated — use getElapsedPercentage.
     */
    public function percentElapsed(Ticket $ticket): ?float
    {
        $pct = $this->getElapsedPercentage($ticket);
        return $pct === null ? null : min(100.0, max(0.0, $pct * 100.0));
    }
}
