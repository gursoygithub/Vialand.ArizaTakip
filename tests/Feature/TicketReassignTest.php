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
use App\Models\TicketStatusHistory;
use App\Models\Unit;
use App\Models\User;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketReassignTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private TicketService $service;
    private Area $area;
    private SubArea $subArea;
    private Unit $unit;
    private SlaPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
        $this->service = app(TicketService::class);

        $this->area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $this->subArea = SubArea::factory()->create(['area_id' => $this->area->id]);
        $this->unit    = Unit::factory()->create();

        $this->policy = SlaPolicy::factory()->create([
            'area_id'          => $this->area->id,
            'sub_area_id'      => $this->subArea->id,
            'unit_id'          => $this->unit->id,
            'priority'         => TaskPriorityEnum::Medium->value,
            'deadline_minutes' => 120,
        ]);
    }

    /**
     * Create an Employee + User linked by the same email (LDAP convention
     * used throughout the codebase — User::employee is a hasOne by email).
     *
     * @return array{Employee, User}
     */
    private function makeAssignee(string $tag): array
    {
        $email    = "reassign-{$tag}@test.com";
        $employee = Employee::factory()->create(['email' => $email]);
        $user     = User::factory()->create(['email' => $email, 'username' => "reassign-{$tag}"]);

        return [$employee, $user];
    }

    public function test_reassign_from_in_progress_resets_status_to_assigned_and_recalculates_sla(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        [$oldEmployee] = $this->makeAssignee('ip-old');
        [$newEmployee] = $this->makeAssignee('ip-new');

        // Ticket in IN_PROGRESS with an expired SLA (already breached).
        $ticket = Ticket::factory()->create([
            'area_id'      => $this->area->id,
            'sub_area_id'  => $this->subArea->id,
            'unit_id'      => $this->unit->id,
            'employee_id'  => $oldEmployee->id,
            'priority'     => TaskPriorityEnum::Medium,
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => Carbon::parse('2026-06-01 08:00:00'),
            'sla_breached' => true,
        ]);

        $historyBefore = TicketStatusHistory::where('ticket_id', $ticket->id)->count();

        $this->service->reassign($ticket, $newEmployee->id, $this->actor);

        $fresh = $ticket->fresh();

        // 1. Status rolled back to ASSIGNED
        $this->assertEquals(TaskStatusEnum::ASSIGNED, $fresh->status);

        // 2. SLA deadline rebased from now() + 120 min = 12:00
        $this->assertNotNull($fresh->sla_deadline);
        $this->assertEquals(
            '2026-06-01 12:00:00',
            $fresh->sla_deadline->toDateTimeString(),
        );

        // 3. Breach flag cleared
        $this->assertFalse((bool) $fresh->sla_breached);

        // 4. History row written for the status rollback (IN_PROGRESS → ASSIGNED)
        $this->assertDatabaseHas('ticket_status_histories', [
            'ticket_id'   => $ticket->id,
            'from_status' => TaskStatusEnum::IN_PROGRESS->value,
            'to_status'   => TaskStatusEnum::ASSIGNED->value,
            'changed_by'  => $this->actor->id,
        ]);

        // 5. Two new history rows total: status reset + employee-change log
        $this->assertSame($historyBefore + 2, TicketStatusHistory::where('ticket_id', $ticket->id)->count());

        Carbon::setTestNow();
    }

    public function test_reassign_from_on_hold_resets_status_clears_on_hold_since_and_recalculates_sla(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        [$oldEmployee] = $this->makeAssignee('oh-old');
        [$newEmployee] = $this->makeAssignee('oh-new');

        // Ticket is ON_HOLD with a stale SLA deadline.
        // ON_HOLD tickets don't flip sla_breached — the clock is paused.
        $ticket = Ticket::factory()->create([
            'area_id'       => $this->area->id,
            'sub_area_id'   => $this->subArea->id,
            'unit_id'       => $this->unit->id,
            'employee_id'   => $oldEmployee->id,
            'priority'      => TaskPriorityEnum::Medium,
            'status'        => TaskStatusEnum::ON_HOLD,
            'on_hold_since' => Carbon::parse('2026-06-01 09:00:00'),
            'sla_deadline'  => Carbon::parse('2026-06-01 08:00:00'),
            'sla_breached'  => false,
        ]);

        $historyBefore = TicketStatusHistory::where('ticket_id', $ticket->id)->count();

        $this->service->reassign($ticket, $newEmployee->id, $this->actor);

        $fresh = $ticket->fresh();

        // 1. Status rolled back to ASSIGNED
        $this->assertEquals(TaskStatusEnum::ASSIGNED, $fresh->status);

        // 2. on_hold_since cleared — stale pause state must not leak into
        //    getRemainingMinutes / getElapsedPercentage for the new assignee
        $this->assertNull($fresh->on_hold_since);

        // 3. SLA deadline rebased from now() + 120 min = 12:00
        $this->assertNotNull($fresh->sla_deadline);
        $this->assertEquals(
            '2026-06-01 12:00:00',
            $fresh->sla_deadline->toDateTimeString(),
        );

        // 4. Breach flag cleared
        $this->assertFalse((bool) $fresh->sla_breached);

        // 5. History row written for the status rollback (ON_HOLD → ASSIGNED)
        $this->assertDatabaseHas('ticket_status_histories', [
            'ticket_id'   => $ticket->id,
            'from_status' => TaskStatusEnum::ON_HOLD->value,
            'to_status'   => TaskStatusEnum::ASSIGNED->value,
            'changed_by'  => $this->actor->id,
        ]);

        // 6. Two new history rows total: status reset + employee-change log
        $this->assertSame($historyBefore + 2, TicketStatusHistory::where('ticket_id', $ticket->id)->count());

        Carbon::setTestNow();
    }

    public function test_reassign_from_assigned_keeps_status_and_does_not_recalculate_sla(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $originalDeadline = Carbon::parse('2026-06-01 14:00:00');

        [$oldEmployee] = $this->makeAssignee('asgn-old');
        [$newEmployee] = $this->makeAssignee('asgn-new');

        $ticket = Ticket::factory()->create([
            'area_id'      => $this->area->id,
            'sub_area_id'  => $this->subArea->id,
            'unit_id'      => $this->unit->id,
            'employee_id'  => $oldEmployee->id,
            'priority'     => TaskPriorityEnum::Medium,
            'status'       => TaskStatusEnum::ASSIGNED,
            'sla_deadline' => $originalDeadline,
            'sla_breached' => false,
        ]);

        // TicketObserver::creating() recalculates sla_deadline when a policy
        // exists, so capture the actual deadline from the DB after creation
        // rather than the value passed to the factory.
        $deadlineAfterCreate = $ticket->fresh()->sla_deadline->copy();

        $historyBefore = TicketStatusHistory::where('ticket_id', $ticket->id)->count();

        $this->service->reassign($ticket, $newEmployee->id, $this->actor);

        $fresh = $ticket->fresh();

        // 1. Status unchanged
        $this->assertEquals(TaskStatusEnum::ASSIGNED, $fresh->status);

        // 2. SLA deadline unchanged — ASSIGNED reassignment must not rebase
        $this->assertNotNull($fresh->sla_deadline);
        $this->assertEquals(
            $deadlineAfterCreate->toDateTimeString(),
            $fresh->sla_deadline->toDateTimeString(),
        );

        // 3. Exactly one new history row: only the employee-change log,
        //    no spurious status-reset row
        $this->assertSame($historyBefore + 1, TicketStatusHistory::where('ticket_id', $ticket->id)->count());

        // 4. That sole new row is the __reassign__ log (from == to, prefixed note)
        $logRow = TicketStatusHistory::where('ticket_id', $ticket->id)
            ->latest('id')
            ->first();

        $this->assertSame(
            $fresh->status->value,
            $logRow->from_status->value,
        );
        $this->assertSame(
            $fresh->status->value,
            $logRow->to_status->value,
        );
        $this->assertStringStartsWith(
            TicketService::REASSIGN_NOTE_PREFIX,
            (string) $logRow->note,
        );

        Carbon::setTestNow();
    }
}
