<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\SlaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLegacyTickets extends Command
{
    protected $signature = 'tickets:backfill-legacy';

    protected $description = 'One-shot: generate ticket_no for legacy tickets and resolve sla_deadline for tickets that lack one';

    public function handle(SlaService $slaService): int
    {
        $this->info('Backfilling legacy tickets…');

        $numbered = $this->backfillTicketNumbers();
        $this->line("  ticket_no generated:  {$numbered}");

        [$resolved, $skipped] = $this->backfillSlaDeadlines($slaService);
        $this->line("  sla_deadline filled:  {$resolved}");
        $this->line("  sla_deadline skipped: {$skipped} (no matching policy)");

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Generate ticket_no for every ticket missing one.
     * Format: TKT-YYYY-NNNNN where YYYY = created_at year and NNNNN is a per-year sequence.
     */
    private function backfillTicketNumbers(): int
    {
        $updated = 0;

        // Pre-seed per-year sequence with the highest existing number for each year so we
        // do not collide with tickets that already have a ticket_no assigned.
        $perYearSequence = [];
        Ticket::withTrashed()
            ->whereNotNull('ticket_no')
            ->orderBy('id')
            ->each(function (Ticket $t) use (&$perYearSequence) {
                if (!preg_match('/^TKT-(\d{4})-(\d+)$/', $t->ticket_no, $m)) {
                    return;
                }
                $year = (int) $m[1];
                $seq  = (int) $m[2];
                $perYearSequence[$year] = max($perYearSequence[$year] ?? 0, $seq);
            });

        Ticket::withTrashed()
            ->whereNull('ticket_no')
            ->orderBy('created_at')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$perYearSequence, &$updated) {
                foreach ($chunk as $ticket) {
                    $year = (int) ($ticket->created_at?->year ?? now()->year);
                    $perYearSequence[$year] = ($perYearSequence[$year] ?? 0) + 1;
                    $no = sprintf('TKT-%04d-%05d', $year, $perYearSequence[$year]);

                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update(['ticket_no' => $no]);

                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * Resolve sla_deadline for every ticket missing one, using current SLA policies.
     * Returns [filled, skipped].
     */
    private function backfillSlaDeadlines(SlaService $slaService): array
    {
        $filled  = 0;
        $skipped = 0;

        Ticket::withTrashed()
            ->whereNull('sla_deadline')
            ->whereNotNull('area_id')
            ->whereNotNull('unit_id')
            ->whereNotNull('priority')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($slaService, &$filled, &$skipped) {
                foreach ($chunk as $ticket) {
                    $priorityValue = is_object($ticket->priority)
                        ? $ticket->priority->value
                        : $ticket->priority;

                    $policy = $slaService->resolvePolicy(
                        $ticket->area_id,
                        $ticket->sub_area_id,
                        $ticket->unit_id,
                        $priorityValue
                    );

                    if (!$policy) {
                        $skipped++;
                        continue;
                    }

                    $start    = $ticket->created_at ?? now();
                    $deadline = $slaService->calculateDeadline($policy, $start);

                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update(['sla_deadline' => $deadline]);

                    $filled++;
                }
            });

        return [$filled, $skipped];
    }
}
