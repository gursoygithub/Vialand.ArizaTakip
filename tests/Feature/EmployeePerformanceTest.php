<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Models\Area;
use App\Models\Employee;
use App\Models\SlaPolicy;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\PerformanceService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePerformanceTest extends TestCase
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

    /**
     * Reproduce the EmployeeResource performance_score badge color closure
     * (post-fix version: both columns nullable → gray when either is null).
     */
    private function performanceColor(Employee $employee): string
    {
        if (is_null($employee->performance_score)) return 'gray';
        if (is_null($employee->current_threshold)) return 'gray';
        $t = $employee->current_threshold;
        return $employee->performance_score >= $t
            ? 'success'
            : ($employee->performance_score >= $t * 0.75 ? 'warning' : 'danger');
    }

    // ── Test 1: New employee, no tickets ────────────────────────────────────

    public function test_new_employee_has_null_performance_metrics(): void
    {
        $employee = Employee::factory()->create();

        $fresh = $employee->fresh();
        $this->assertNull($fresh->performance_score);
        $this->assertNull($fresh->current_threshold);
        $this->assertSame('gray', $this->performanceColor($fresh));
    }

    // ── Test 2: refreshPerformanceMetrics() with zero sealed tickets ─────────

    public function test_refresh_metrics_with_zero_sealed_tickets_sets_null(): void
    {
        $area     = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit     = Unit::factory()->create();
        $employee = Employee::factory()->create();

        // OPEN ticket — not resolved, not breached → outside the sealed cohort.
        Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'employee_id' => $employee->id,
            'status'      => TaskStatusEnum::OPEN,
        ]);

        $employee->refreshPerformanceMetrics();

        $fresh = $employee->fresh();
        $this->assertNull($fresh->performance_score);
        $this->assertNull($fresh->current_threshold);
    }

    // ── Test 3: Employee resolves ticket on time ─────────────────────────────

    public function test_resolved_on_time_gives_100_score_and_policy_threshold(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        SlaPolicy::factory()->create([
            'area_id'           => $area->id,
            'sub_area_id'       => $subArea->id,
            'unit_id'           => $unit->id,
            'priority'          => TaskPriorityEnum::Medium->value,
            'deadline_minutes'  => 120,
            'success_threshold' => 80,
        ]);

        $employee = Employee::factory()->create();

        // Observer sets sla_deadline = now() + 120 min = 12:00 (in the future).
        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'employee_id' => $employee->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        // markResolved(): resolved_at = 10:00, sla_breached = (10:00 > 12:00) = false.
        // saved() hook: wasChanged('resolved_at') = true → refreshPerformanceMetrics.
        $this->service->transition($ticket, TaskStatusEnum::RESOLVED, $this->actor);

        $fresh = $employee->fresh();
        $this->assertEquals(100.0, $fresh->performance_score);
        $this->assertEquals(80.0, $fresh->current_threshold);

        Carbon::setTestNow();
    }

    // ── Test 4: Employee resolves ticket late (breach) ───────────────────────

    public function test_resolved_late_gives_zero_score_and_breach_flag(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        // No SlaPolicy for this combination → observer leaves our factory
        // sla_deadline untouched on creating.
        $employee = Employee::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $subArea->id,
            'unit_id'      => $unit->id,
            'employee_id'  => $employee->id,
            'priority'     => TaskPriorityEnum::Medium,
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => Carbon::parse('2026-06-01 08:00:00'), // past
            'sla_breached' => true,
        ]);

        // markResolved(): resolved_at = 10:00, sla_breached = (10:00 > 08:00) = true.
        $this->service->transition($ticket, TaskStatusEnum::RESOLVED, $this->actor);

        $fresh = $employee->fresh();
        $this->assertEquals(0.0, $fresh->performance_score);
        $this->assertTrue((bool) $ticket->fresh()->sla_breached);

        Carbon::setTestNow();
    }

    // ── Test 5: Ticket reassigned, both employees recalculated ───────────────

    public function test_reassign_recalculates_both_employees(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        SlaPolicy::factory()->create([
            'area_id'           => $area->id,
            'sub_area_id'       => $subArea->id,
            'unit_id'           => $unit->id,
            'priority'          => TaskPriorityEnum::Medium->value,
            'deadline_minutes'  => 120,
            'success_threshold' => 80,
        ]);

        $employeeA = Employee::factory()->create();
        $employeeB = Employee::factory()->create();

        // Assign to A and resolve on time → A score = 100.
        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'employee_id' => $employeeA->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        $this->service->transition($ticket, TaskStatusEnum::RESOLVED, $this->actor);
        $this->assertEquals(100.0, $employeeA->fresh()->performance_score);

        // Reassign to B.
        // B6: after employee_id update, A's refreshPerformanceMetrics is called
        //     directly → ticket no longer in A's cohort → null metrics.
        // B7: saved() hook sees wasChanged('employee_id') = true → recalculates B.
        $this->service->reassign($ticket->fresh(), $employeeB->id, $this->actor);

        // A: cohort is now empty (ticket belongs to B) → null metrics.
        $this->assertNull($employeeA->fresh()->performance_score);

        // B: ticket has resolved_at set and sla_breached = false → score = 100.
        $this->assertEquals(100.0, $employeeB->fresh()->performance_score);

        Carbon::setTestNow();
    }

    // ── Test 7: SLA-less ticket excluded from score ──────────────────────────

    public function test_sla_less_ticket_excluded_from_performance_score(): void
    {
        $area     = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit     = Unit::factory()->create();
        $employee = Employee::factory()->create();

        // No SlaPolicy → observer leaves sla_deadline = null on creating.
        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'employee_id' => $employee->id,
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        // Resolve: resolved_at set, sla_deadline = null, sla_breached = false.
        // One true formula: success requires sla_deadline IS NOT NULL → excluded.
        $this->service->transition($ticket, TaskStatusEnum::RESOLVED, $this->actor);

        $fresh = $employee->fresh();
        $this->assertNull($fresh->performance_score, 'SLA-less ticket must not contribute to score');
    }

    // ── Test 8: Active breach excluded from score ────────────────────────────

    public function test_active_breach_excluded_from_performance_score(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $area     = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit     = Unit::factory()->create();
        $employee = Employee::factory()->create();

        // Ticket is actively breached but NOT yet resolved (resolved_at = null).
        Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $subArea->id,
            'unit_id'      => $unit->id,
            'employee_id'  => $employee->id,
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => Carbon::parse('2026-06-01 08:00:00'),
            'sla_breached' => true,
            // resolved_at is intentionally not set
        ]);

        $employee->refreshPerformanceMetrics();

        $fresh = $employee->fresh();
        $this->assertNull($fresh->performance_score, 'Active (unresolved) breach must not contribute to score');

        Carbon::setTestNow();
    }

    // ── Test 9: Cancelled ticket excluded from score ─────────────────────────

    public function test_cancelled_ticket_excluded_from_performance_score(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $area     = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit     = Unit::factory()->create();

        SlaPolicy::factory()->create([
            'area_id'           => $area->id,
            'sub_area_id'       => $subArea->id,
            'unit_id'           => $unit->id,
            'priority'          => TaskPriorityEnum::Medium->value,
            'deadline_minutes'  => 120,
            'success_threshold' => 80,
        ]);

        $employee = Employee::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'employee_id' => $employee->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        // Cancel — markCancelled clears sla_breached; resolved_at is never set.
        $this->service->transition($ticket, TaskStatusEnum::CANCELLED, $this->actor);

        $fresh = $employee->fresh();
        $this->assertNull($fresh->performance_score, 'Cancelled ticket must be excluded from score');

        Carbon::setTestNow();
    }

    // ── Test 10: Dashboard date filter uses resolved_at ──────────────────────

    public function test_dashboard_date_filter_uses_resolved_at(): void
    {
        $this->actor->assignRole('super_admin');

        // Create ticket in January.
        Carbon::setTestNow('2026-01-15 10:00:00');

        $area     = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit     = Unit::factory()->create();
        $employee = Employee::factory()->create();

        SlaPolicy::factory()->create([
            'area_id'           => $area->id,
            'sub_area_id'       => $subArea->id,
            'unit_id'           => $unit->id,
            'priority'          => TaskPriorityEnum::Medium->value,
            'deadline_minutes'  => 120,
            'success_threshold' => 80,
        ]);

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'employee_id' => $employee->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        // Resolve in February → resolved_at = 2026-02-15.
        Carbon::setTestNow('2026-02-15 10:00:00');
        $this->service->transition($ticket, TaskStatusEnum::RESOLVED, $this->actor);
        Carbon::setTestNow();

        $performanceService = app(PerformanceService::class);

        // February window (resolved_at in range) → ticket must appear.
        $feb = $performanceService->getOverview(
            Carbon::parse('2026-02-01 00:00:00'),
            Carbon::parse('2026-02-28 23:59:59'),
            $this->actor
        );
        $this->assertGreaterThan(0, $feb['total_assigned'],
            'Ticket resolved in February must appear in the February date range');

        // January window (resolved_at NOT in range) → ticket must not appear.
        $jan = $performanceService->getOverview(
            Carbon::parse('2026-01-01 00:00:00'),
            Carbon::parse('2026-01-31 23:59:59'),
            $this->actor
        );
        $this->assertEquals(0, $jan['total_assigned'],
            'Ticket resolved in February must NOT appear in the January date range');
    }

    // ── Test 6: No recalc on non-cohort save ─────────────────────────────────

    public function test_non_cohort_save_does_not_trigger_recalculation(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        SlaPolicy::factory()->create([
            'area_id'           => $area->id,
            'sub_area_id'       => $subArea->id,
            'unit_id'           => $unit->id,
            'priority'          => TaskPriorityEnum::Medium->value,
            'deadline_minutes'  => 120,
            'success_threshold' => 80,
        ]);

        $employee = Employee::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'employee_id' => $employee->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        // Establish a real baseline score via normal lifecycle.
        $this->service->transition($ticket, TaskStatusEnum::RESOLVED, $this->actor);
        $this->assertNotNull($employee->fresh()->performance_score);

        // Poke the score to a sentinel value that refreshPerformanceMetrics
        // would never naturally produce (42.0 with one on-time ticket → 100.0).
        // This lets us distinguish "guard fired, recalculated to 100" from
        // "guard blocked, score stayed at sentinel".
        $employee->update(['performance_score' => 42.0]);
        $this->assertEquals(42.0, $employee->fresh()->performance_score);

        // Update only description — wasChanged(['sla_breached','resolved_at','employee_id'])
        // = false → saved() guard returns early, refreshPerformanceMetrics NOT called.
        $ticket->fresh()->update(['description' => 'Changed description for guard test']);

        $this->assertEquals(42.0, $employee->fresh()->performance_score);

        Carbon::setTestNow();
    }
}
