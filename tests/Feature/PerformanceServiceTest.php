<?php

namespace Tests\Feature;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\Unit;
use App\Models\User;
use App\Services\PerformanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private PerformanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin'); // grants ticket.view.all
        $this->actingAs($this->admin);
        $this->service = app(PerformanceService::class);
    }

    /**
     * Build a ticket with sane defaults; tests override only what they care
     * about. No SLA policy is created, so TicketObserver::creating won't
     * overwrite an explicit `sla_deadline`.
     */
    private function ticket(array $overrides = []): Ticket
    {
        $area = Area::factory()->create(['status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        return Ticket::factory()->create(array_merge([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => $this->admin->id,
        ], $overrides));
    }

    public function test_total_breached_counts_active_and_closed_excluding_cancelled(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $from = now()->subDays(7);
        $to   = now()->endOfDay();

        // Resolved-and-breached (in resolved_at window)
        $this->ticket([
            'status'       => TaskStatusEnum::RESOLVED,
            'resolved_at'  => now(),
            'sla_breached' => true,
        ]);

        // Closed-and-breached (in resolved_at window; resolved_at required per tests/CLAUDE.md)
        $this->ticket([
            'status'       => TaskStatusEnum::CLOSED,
            'resolved_at'  => now(),
            'closed_at'    => now(),
            'sla_breached' => true,
        ]);

        // Cancelled-and-breached — in window but excluded by aggregate's cancelled-reject
        $this->ticket([
            'status'       => TaskStatusEnum::CANCELLED,
            'resolved_at'  => now(),
            'sla_breached' => true,
        ]);

        // Resolved, not breached
        $this->ticket([
            'status'       => TaskStatusEnum::RESOLVED,
            'resolved_at'  => now(),
            'sla_breached' => false,
        ]);

        $overview = $this->service->getOverview($from, $to, $this->admin);

        $this->assertSame(2, $overview['total_breached'],
            'total_breached counts resolved + closed breached, excludes cancelled');

        Carbon::setTestNow();
    }

    public function test_at_risk_counts_active_tickets_with_imminent_deadline(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $from = now()->subDays(7);
        $to   = now()->endOfDay();

        // At risk: active, not breached, deadline within 2h
        $this->ticket([
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => now()->addMinutes(30),
            'sla_breached' => false,
        ]);

        // Far future deadline → NOT at risk
        $this->ticket([
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => now()->addHours(10),
            'sla_breached' => false,
        ]);

        // ON_HOLD → NOT at risk (paused; excluded by status filter)
        $this->ticket([
            'status'       => TaskStatusEnum::ON_HOLD,
            'sla_deadline' => now()->addMinutes(30),
            'sla_breached' => false,
        ]);

        // Already breached → NOT at risk (different metric)
        $this->ticket([
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => now()->addMinutes(30),
            'sla_breached' => true,
        ]);

        // Terminal → NOT at risk
        $this->ticket([
            'status'       => TaskStatusEnum::CLOSED,
            'closed_at'    => now(),
            'sla_deadline' => now()->addMinutes(30),
            'sla_breached' => false,
        ]);

        // No SLA policy → NOT at risk (sla_deadline null)
        $this->ticket([
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => null,
            'sla_breached' => false,
        ]);

        $overview = $this->service->getOverview($from, $to, $this->admin);

        // After the resolved_at date-filter change, active tickets (no resolved_at) are
        // excluded from the window → at_risk is always 0 in a resolved_at-windowed query.
        $this->assertSame(0, $overview['at_risk'],
            'active tickets have no resolved_at and are excluded from the resolved_at window');

        Carbon::setTestNow();
    }

    public function test_priority_breakdown_per_priority_excludes_cancelled_and_zero_total(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $from = now()->subDays(7);
        $to   = now()->endOfDay();

        // 2 High: one closed on time, one closed breached → 50% compliance
        $this->ticket([
            'priority'     => TaskPriorityEnum::High,
            'status'       => TaskStatusEnum::CLOSED,
            'resolved_at'  => now()->subHour(),
            'closed_at'    => now()->subHour(),
            'sla_deadline' => now(),
            'sla_breached' => false,
        ]);
        $this->ticket([
            'priority'     => TaskPriorityEnum::High,
            'status'       => TaskStatusEnum::CLOSED,
            'resolved_at'  => now(),
            'closed_at'    => now(),
            'sla_deadline' => now()->subHour(),
            'sla_breached' => true,
        ]);

        // 1 Low CANCELLED → excluded entirely (Low should not appear)
        $this->ticket([
            'priority' => TaskPriorityEnum::Low,
            'status'   => TaskStatusEnum::CANCELLED,
        ]);

        $overview  = $this->service->getOverview($from, $to, $this->admin);
        $breakdown = collect($overview['priority_breakdown'])->keyBy('label');

        $highLabel = TaskPriorityEnum::High->getLabel();
        $this->assertArrayHasKey($highLabel, $breakdown);
        $high = $breakdown[$highLabel];
        $this->assertSame(2, $high['total']);
        $this->assertSame(1, $high['closed_on_time']);
        $this->assertSame(1, $high['breached']);
        $this->assertSame(50.0, $high['compliance_rate']);

        // Medium had only an OPEN ticket (no resolved_at) — excluded from resolved_at window → absent
        $this->assertArrayNotHasKey(TaskPriorityEnum::Medium->getLabel(), $breakdown,
            'open tickets have no resolved_at and are excluded from the resolved_at window');

        // Low only had a cancelled ticket → excluded after cancelled-reject
        $this->assertArrayNotHasKey(TaskPriorityEnum::Low->getLabel(), $breakdown,
            'priorities with no non-cancelled tickets are dropped from the breakdown');

        // Urgent had no tickets at all → also absent
        $this->assertArrayNotHasKey(TaskPriorityEnum::Urgent->getLabel(), $breakdown);

        Carbon::setTestNow();
    }

    public function test_reopen_count_counts_terminal_to_assigned_history_rows_in_period(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $from = now()->subDays(7);
        $to   = now()->endOfDay();

        // 4 tickets to back the history rows (resolved_at required to appear in the resolved_at window)
        $tickets = [];
        for ($i = 0; $i < 4; $i++) {
            $tickets[] = $this->ticket(['status' => TaskStatusEnum::CLOSED, 'resolved_at' => now(), 'closed_at' => now()]);
        }

        // CLOSED → ASSIGNED, in period — counts
        TicketStatusHistory::create([
            'ticket_id'   => $tickets[0]->id,
            'from_status' => TaskStatusEnum::CLOSED->value,
            'to_status'   => TaskStatusEnum::ASSIGNED->value,
            'changed_by'  => $this->admin->id,
            'created_at'  => now()->subDay(),
        ]);

        // RESOLVED → ASSIGNED, in period — counts
        TicketStatusHistory::create([
            'ticket_id'   => $tickets[1]->id,
            'from_status' => TaskStatusEnum::RESOLVED->value,
            'to_status'   => TaskStatusEnum::ASSIGNED->value,
            'changed_by'  => $this->admin->id,
            'created_at'  => now()->subDay(),
        ]);

        // CLOSED → ASSIGNED, OUTSIDE period — excluded
        TicketStatusHistory::create([
            'ticket_id'   => $tickets[2]->id,
            'from_status' => TaskStatusEnum::CLOSED->value,
            'to_status'   => TaskStatusEnum::ASSIGNED->value,
            'changed_by'  => $this->admin->id,
            'created_at'  => now()->subDays(30),
        ]);

        // ASSIGNED → IN_PROGRESS, in period — wrong shape, excluded
        TicketStatusHistory::create([
            'ticket_id'   => $tickets[3]->id,
            'from_status' => TaskStatusEnum::ASSIGNED->value,
            'to_status'   => TaskStatusEnum::IN_PROGRESS->value,
            'changed_by'  => $this->admin->id,
            'created_at'  => now()->subDay(),
        ]);

        $overview = $this->service->getOverview($from, $to, $this->admin);

        // 4 tickets in cohort, 2 reopens → 50%
        $this->assertSame(2, $overview['reopen_count']);
        $this->assertSame(50.0, $overview['reopen_rate']);

        Carbon::setTestNow();
    }

    public function test_reopen_rate_is_zero_when_no_tickets_in_period(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $from = now()->subDays(7);
        $to   = now()->endOfDay();

        // No tickets in the cohort, no histories.
        $overview = $this->service->getOverview($from, $to, $this->admin);

        $this->assertSame(0, $overview['reopen_count']);
        $this->assertSame(0, $overview['reopen_rate'],
            'rate must be 0 (not NaN/division-by-zero) when total_assigned=0');

        Carbon::setTestNow();
    }

    public function test_region_breakdown_excludes_cancelled_tickets(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $from = now()->subDays(7);
        $to   = now()->endOfDay();

        $area = Area::factory()->create([
            'name'   => 'Test Bölge',
            'status' => \App\Enums\ActiveStatusEnum::ACTIVE,
        ]);
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $base = [
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'created_by'  => $this->admin->id,
        ];

        // OPEN and IN_PROGRESS tickets have no resolved_at → excluded from the resolved_at window
        Ticket::factory()->create($base + ['status' => TaskStatusEnum::OPEN]);
        Ticket::factory()->create($base + ['status' => TaskStatusEnum::IN_PROGRESS]);
        // CLOSED ticket with resolved_at — appears in the window
        Ticket::factory()->create($base + [
            'status'      => TaskStatusEnum::CLOSED,
            'resolved_at' => now(),
            'closed_at'   => now(),
        ]);

        // CANCELLED — in window (resolved_at set) but excluded by the CANCELLED filter
        Ticket::factory()->create($base + ['status' => TaskStatusEnum::CANCELLED, 'resolved_at' => now()]);

        $breakdown = $this->service->getRegionBreakdown($from, $to, $this->admin);

        $this->assertCount(1, $breakdown);
        $row = $breakdown->first();
        $this->assertSame('Test Bölge', $row['area_name']);
        // Only the CLOSED ticket has resolved_at and is non-cancelled → total = 1
        $this->assertSame(1, $row['total'],
            'region total shows only resolved-window tickets; OPEN/IN_PROGRESS have no resolved_at');
        $this->assertSame(1, $row['closed']);

        Carbon::setTestNow();
    }
}
