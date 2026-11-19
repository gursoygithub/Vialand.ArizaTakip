<?php

namespace App\Jobs;

use App\Enums\TaskStatusEnum;
use App\Events\TeamTicketSlaBreached;
use App\Events\TeamTicketSlaWarning;
use App\Exceptions\TicketWorkflowException;
use App\Models\Ticket;
use App\Models\TicketHold;
use App\Models\TicketSlaCycle;
use App\Services\TeamTicketNotifier;
use App\Services\TicketWorkflowService;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Periodic sweep for team tickets:
 *  1. response SLA breaches of tickets nobody has started,
 *  2. resolution SLA breaches (flag + attribution + event, once),
 *  3. the 80% warning (event, once per cycle),
 *  4. escalation to managers when a breached ticket is still open after
 *     `general.sla_escalation_hours` (once per cycle, 0 = off),
 *  5. auto-closing resolutions the requester never confirmed,
 *  6. alerting the supervisor once when a hold outlasts the team's
 *     `hold_alert_minutes` (working minutes, 0 = off).
 */
class ProcessTeamTicketSla implements ShouldQueue
{
    use Queueable;

    public function handle(TicketWorkflowService $workflow, TeamTicketNotifier $notifier, GeneralSettings $settings): void
    {
        $now = CarbonImmutable::now();

        $this->runningCycles()
            ->whereNull('responded_at')
            ->where('response_breached', false)
            ->where('response_due_at', '<', $now)
            ->chunkById(100, function ($cycles) use ($workflow, $now): void {
                foreach ($cycles as $cycle) {
                    $workflow->flagResponseBreach($cycle, $now);
                }
            });

        $this->runningCycles()
            ->whereNull('paused_since')
            ->where('resolution_breached', false)
            ->where('resolution_due_at', '<', $now)
            ->chunkById(100, function ($cycles) use ($workflow, $notifier, $now): void {
                foreach ($cycles as $cycle) {
                    if ($workflow->flagResolutionBreach($cycle, $now)) {
                        $cycle->forceFill(['breach_notified_at' => $now])->save();
                        event(new TeamTicketSlaBreached($cycle));
                        $notifier->slaBreached($cycle);
                    }
                }
            });

        $this->runningCycles()
            ->whereNull('paused_since')
            ->whereNull('warning_sent_at')
            ->where('resolution_breached', false)
            ->where('resolution_due_at', '>', $now)
            ->chunkById(100, function ($cycles) use ($workflow, $notifier, $now): void {
                foreach ($cycles as $cycle) {
                    if ($workflow->elapsedRatio($cycle, $now) >= TicketWorkflowService::WARNING_RATIO) {
                        $cycle->forceFill(['warning_sent_at' => $now])->save();
                        event(new TeamTicketSlaWarning($cycle));
                        $notifier->slaWarning($cycle);
                    }
                }
            });

        if ($settings->sla_escalation_hours > 0) {
            $this->runningCycles()
                ->where('resolution_breached', true)
                ->whereNull('escalated_at')
                ->where('breached_at', '<=', $now->subHours($settings->sla_escalation_hours))
                ->chunkById(100, function ($cycles) use ($notifier, $now): void {
                    foreach ($cycles as $cycle) {
                        $cycle->forceFill(['escalated_at' => $now])->save();
                        $notifier->slaEscalated($cycle);
                    }
                });
        }

        TicketHold::query()
            ->open()
            ->whereNull('alerted_at')
            ->whereHas('team', fn ($query) => $query->where('hold_alert_minutes', '>', 0))
            ->whereHas('ticket', fn ($query) => $query->where('status', TaskStatusEnum::ON_HOLD))
            ->with(['team.workCalendar', 'ticket'])
            ->chunkById(100, function ($holds) use ($workflow, $notifier, $now): void {
                foreach ($holds as $hold) {
                    if ($workflow->holdWorkingMinutes($hold, $now) >= $hold->team->hold_alert_minutes) {
                        $hold->forceFill(['alerted_at' => $now])->save();
                        $notifier->holdOverdue($hold);
                    }
                }
            });

        Ticket::query()
            ->whereNotNull('team_id')
            ->where('status', TaskStatusEnum::RESOLVED)
            ->where('auto_close_at', '<=', $now)
            ->chunkById(100, function ($tickets) use ($workflow): void {
                foreach ($tickets as $ticket) {
                    try {
                        $workflow->autoClose($ticket);
                    } catch (TicketWorkflowException $exception) {
                        Log::warning('Team ticket auto-close skipped', [
                            'ticket_id' => $ticket->id,
                            'reason' => $exception->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function runningCycles()
    {
        return TicketSlaCycle::query()
            ->running()
            ->whereHas('ticket', fn ($query) => $query->whereIn('status', [
                TaskStatusEnum::OPEN,
                TaskStatusEnum::ASSIGNED,
                TaskStatusEnum::IN_PROGRESS,
                TaskStatusEnum::ON_HOLD,
            ]))
            ->with(['ticket', 'team.slaPolicies', 'workCalendar']);
    }
}
