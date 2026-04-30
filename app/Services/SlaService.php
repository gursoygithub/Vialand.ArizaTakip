<?php

namespace App\Services;

use App\Models\SlaPolicy;
use App\Models\Ticket;
use Carbon\Carbon;

class SlaService
{
    /**
     * Resolve the most specific SLA policy for a ticket.
     * Lookup order: (area + subArea + unit + priority) → (area + unit + priority).
     * The sub_area column is currently NOT NULL in the schema, so the fallback
     * matches any policy at area+unit+priority regardless of sub_area.
     */
    public function resolvePolicy(int $areaId, ?int $subAreaId, int $unitId, int|string $priority): ?SlaPolicy
    {
        if ($subAreaId) {
            $policy = SlaPolicy::where('area_id', $areaId)
                ->where('sub_area_id', $subAreaId)
                ->where('unit_id', $unitId)
                ->where('priority', $priority)
                ->first();

            if ($policy) {
                return $policy;
            }
        }

        return SlaPolicy::where('area_id', $areaId)
            ->where('unit_id', $unitId)
            ->where('priority', $priority)
            ->first();
    }

    /**
     * Calculate the SLA deadline from a given start time.
     */
    public function calculateDeadline(SlaPolicy $policy, Carbon $from): Carbon
    {
        return $from->copy()->addMinutes($policy->deadline_minutes);
    }

    /**
     * Check whether a ticket has breached its SLA.
     * Returns true if sla_deadline is set, is in the past, and ticket is not closed.
     */
    public function checkBreach(Ticket $ticket): bool
    {
        if (!$ticket->sla_deadline) {
            return false;
        }

        if ($ticket->status?->isClosed()) {
            return false;
        }

        return now()->isAfter($ticket->sla_deadline);
    }

    /**
     * Calculate percentage of SLA time elapsed (0–100).
     * Returns null if no SLA deadline is set.
     */
    public function percentElapsed(Ticket $ticket): ?float
    {
        if (!$ticket->sla_deadline || !$ticket->created_at) {
            return null;
        }

        $total     = $ticket->created_at->diffInSeconds($ticket->sla_deadline);
        $elapsed   = $ticket->created_at->diffInSeconds(now());

        if ($total <= 0) {
            return 100;
        }

        return min(100, max(0, ($elapsed / $total) * 100));
    }
}
