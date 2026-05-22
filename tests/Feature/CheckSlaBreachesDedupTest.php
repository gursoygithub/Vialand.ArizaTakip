<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Events\TicketSlaBreached;
use App\Jobs\CheckSlaBreaches;
use App\Models\Area;
use App\Models\Employee;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckSlaBreachesDedupTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
        CheckSlaBreaches::$inProgress = false;
    }

    protected function tearDown(): void
    {
        CheckSlaBreaches::$inProgress = false;
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Count how many times refreshPerformanceMetrics fires by listening
     * for Employee model updates that change performance_score.
     */
    private function countPerformanceRecalcs(\Closure $callback): int
    {
        $count = 0;

        Employee::updating(function (Employee $employee) use (&$count) {
            if ($employee->isDirty('performance_score')) {
                $count++;
            }
        });

        $callback();

        return $count;
    }

    /**
     * Create a ticket that will breach when time advances past its deadline.
     * Deadline is set in the future so the Observer doesn't flip sla_breached
     * at create time; call Carbon::setTestNow() to advance past deadline.
     */
    private function makeBreachingTicket(Employee $employee, Area $area, SubArea $subArea, Unit $unit): Ticket
    {
        return Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $subArea->id,
            'unit_id'      => $unit->id,
            'employee_id'  => $employee->id,
            'status'       => TaskStatusEnum::ASSIGNED,
            'priority'     => TaskPriorityEnum::Medium,
            'sla_deadline' => now()->addMinutes(30),
            'sla_breached' => false,
        ]);
    }

    public function test_single_employee_with_multiple_breaching_tickets_recalcs_once(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        Event::fake([TicketSlaBreached::class]);
        Notification::fake();

        $area     = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit     = Unit::factory()->create();
        $employee = Employee::factory()->create();

        // Create with deadline 30 min ahead (observer won't flip sla_breached)
        $tickets = collect();
        for ($i = 0; $i < 5; $i++) {
            $tickets->push($this->makeBreachingTicket($employee, $area, $subArea, $unit));
        }

        // Set sentinel so Eloquent detects the change when refreshPerformanceMetrics resets to null
        $employee->update(['performance_score' => 99.0, 'current_threshold' => 50.0]);

        // Advance time past all deadlines
        Carbon::setTestNow('2026-06-01 12:00:00');

        $recalcCount = $this->countPerformanceRecalcs(function () {
            (new CheckSlaBreaches)->handle();
        });

        // All 5 tickets should be breached
        foreach ($tickets as $ticket) {
            $this->assertTrue((bool) $ticket->fresh()->sla_breached);
        }

        // refreshPerformanceMetrics called exactly once (deduped)
        $this->assertEquals(1, $recalcCount);
    }

    public function test_multiple_employees_with_breaching_tickets_recalcs_once_each(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        Event::fake([TicketSlaBreached::class]);
        Notification::fake();

        $area     = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit     = Unit::factory()->create();

        $employeeA = Employee::factory()->create();
        $employeeB = Employee::factory()->create();

        // Create with deadline 30 min ahead
        for ($i = 0; $i < 3; $i++) {
            $this->makeBreachingTicket($employeeA, $area, $subArea, $unit);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makeBreachingTicket($employeeB, $area, $subArea, $unit);
        }

        // Set sentinels so Eloquent detects the change when refreshPerformanceMetrics resets to null
        $employeeA->update(['performance_score' => 99.0, 'current_threshold' => 50.0]);
        $employeeB->update(['performance_score' => 99.0, 'current_threshold' => 50.0]);

        // Advance time past all deadlines
        Carbon::setTestNow('2026-06-01 12:00:00');

        $recalcCount = $this->countPerformanceRecalcs(function () {
            (new CheckSlaBreaches)->handle();
        });

        // refreshPerformanceMetrics called exactly 2 times (once per employee)
        $this->assertEquals(2, $recalcCount);
    }

    public function test_normal_ticket_save_still_triggers_recalc(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $this->assertFalse(CheckSlaBreaches::$inProgress);

        $area     = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit     = Unit::factory()->create();
        $employee = Employee::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $subArea->id,
            'unit_id'      => $unit->id,
            'employee_id'  => $employee->id,
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'priority'     => TaskPriorityEnum::Medium,
            'sla_deadline' => now()->addHours(2),
            'sla_breached' => false,
        ]);

        // Set sentinel so we can detect if refreshPerformanceMetrics ran
        $employee->update(['performance_score' => 42.0]);
        $this->assertEquals(42.0, $employee->fresh()->performance_score);

        // Resolve the ticket — this changes resolved_at, triggering saved() hook
        $recalcCount = $this->countPerformanceRecalcs(function () use ($ticket) {
            $service = app(\App\Services\TicketService::class);
            $service->transition($ticket, TaskStatusEnum::RESOLVED, $this->actor);
        });

        // Outside the bulk job, recalc should fire normally
        $this->assertGreaterThanOrEqual(1, $recalcCount);
        $this->assertNotEquals(42.0, $employee->fresh()->performance_score);
    }
}
