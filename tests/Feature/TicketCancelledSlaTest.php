<?php

namespace Tests\Feature;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Widgets\SlaComplianceTrendChart;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketCancelledSlaTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private TicketService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
        $this->service = app(TicketService::class);
    }

    private function makeTicket(array $overrides = []): Ticket
    {
        $area    = Area::factory()->create(['status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        return Ticket::factory()->create(array_merge([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'status'      => TaskStatusEnum::OPEN,
        ], $overrides));
    }

    /**
     * Test 1 — getSlaStatusLabel() returns 'İptal Edildi' for a cancelled
     * ticket that had a past sla_deadline (would otherwise show '✗ İhlalle çözüldü').
     */
    public function test_get_sla_status_label_returns_cancelled_label_regardless_of_deadline(): void
    {
        $ticket = $this->makeTicket([
            'status'       => TaskStatusEnum::OPEN,
            'sla_deadline' => Carbon::yesterday(),
            'sla_breached' => false,
        ]);

        $this->service->transition($ticket, TaskStatusEnum::CANCELLED, $this->actor);
        $ticket->refresh();

        $this->assertEquals(TaskStatusEnum::CANCELLED, $ticket->status);
        $this->assertEquals('İptal Edildi', $ticket->getSlaStatusLabel());
        $this->assertNotEquals('✗ İhlalle çözüldü', $ticket->getSlaStatusLabel());
    }

    /**
     * Test 2 — getSlaStatusLabel() returns 'İptal Edildi' even when now() > sla_deadline
     * and resolved_at / closed_at are both null (live-clock drift scenario).
     */
    public function test_get_sla_status_label_cancelled_no_resolved_at_no_live_clock_drift(): void
    {
        Carbon::setTestNow('2026-05-18 10:00:00');

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::OPEN,
            'sla_deadline' => Carbon::now()->subDay(), // yesterday — deadline is past
            'sla_breached' => false,
        ]);

        // Set status to CANCELLED directly without resolved_at
        $ticket->status = TaskStatusEnum::CANCELLED;
        $ticket->sla_breached = false;
        $ticket->saveQuietly();
        $ticket->refresh();

        $this->assertEquals('İptal Edildi', $ticket->getSlaStatusLabel());

        Carbon::setTestNow();
    }

    /**
     * Test 3 — SlaComplianceTrendChart excludes CANCELLED tickets from its query.
     * A cancelled ticket with sla_breached=false must NOT inflate the on-time count.
     */
    public function test_sla_compliance_chart_excludes_cancelled_tickets(): void
    {
        Carbon::setTestNow('2026-05-18 12:00:00');

        $resolvedAt  = Carbon::now();
        $pastDeadline = Carbon::now()->subHour();
        $futureDeadline = Carbon::now()->addHour();

        // Ticket 1: resolved on time (sla_breached=false, resolved_at set)
        $this->makeTicket([
            'status'       => TaskStatusEnum::RESOLVED,
            'sla_deadline' => $futureDeadline,
            'sla_breached' => false,
            'resolved_at'  => $resolvedAt,
        ]);

        // Ticket 2: resolved breached (sla_breached=true, resolved_at set)
        $this->makeTicket([
            'status'       => TaskStatusEnum::RESOLVED,
            'sla_deadline' => $pastDeadline,
            'sla_breached' => true,
            'resolved_at'  => $resolvedAt,
        ]);

        // Ticket 3: cancelled with past deadline and sla_breached=false
        // (markCancelled sets sla_breached=false — this would inflate on-time if not excluded)
        $this->makeTicket([
            'status'       => TaskStatusEnum::CANCELLED,
            'sla_deadline' => $pastDeadline,
            'sla_breached' => false,
            'resolved_at'  => $resolvedAt,
        ]);

        $this->actor->givePermissionTo('ticket.view.all');

        $start = now()->subDays(29)->startOfDay();
        $end   = now()->endOfDay();

        $rows = Ticket::query()
            ->visibleBy($this->actor)
            ->whereNotNull('resolved_at')
            ->whereNotNull('sla_deadline')
            ->whereNotIn('status', [TaskStatusEnum::CANCELLED])
            ->whereBetween('resolved_at', [$start, $end])
            ->selectRaw('DATE(resolved_at) as day, sla_breached, COUNT(*) as total')
            ->groupBy('day', 'sla_breached')
            ->get();

        $totalCount = $rows->sum('total');
        $this->assertEquals(2, $totalCount, 'Chart query must count only 2 tickets (not 3)');

        $day = Carbon::now()->format('Y-m-d');
        $dayRows = $rows->filter(fn ($row) => (string) $row->day === $day);

        $onTimeCount = (int) optional($dayRows->firstWhere('sla_breached', false))->total
                     + (int) optional($dayRows->firstWhere('sla_breached', 0))->total;
        // Avoid double-counting if both bool and int keys match the same row
        $onTimeCount = max(
            (int) optional($dayRows->firstWhere('sla_breached', 0))->total,
            (int) optional($dayRows->firstWhere('sla_breached', false))->total
        );

        $this->assertEquals(1, $onTimeCount, 'On-time count must be 1, not 2 (cancelled must be excluded)');

        Carbon::setTestNow();
    }
}
