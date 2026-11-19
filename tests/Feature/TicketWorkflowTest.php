<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\BreachAttributionEnum;
use App\Enums\FormFieldTypeEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\RejectionReasonEnum;
use App\Enums\SlaCycleOutcomeEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\UserStatusEnum;
use App\Exceptions\TicketWorkflowException;
use App\Filament\Resources\TeamTicketResource;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Team workflow rules from the agreement. The team runs 7/24 (no calendar)
 * so minutes are plain clock minutes; Urgent = 15 min response / 120 min
 * resolution.
 */
class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private TicketWorkflowService $workflow;

    private Team $team;

    private User $requester;

    private User $supervisor;

    private User $tech1;

    private User $tech2;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));

        $this->requester = $this->person()[1];
        [$supervisorEmployee, $this->supervisor] = $this->person();
        [$tech1Employee, $this->tech1] = $this->person();
        [$tech2Employee, $this->tech2] = $this->person();
        $this->outsider = $this->person()[1];

        $this->actingAs($this->requester);

        $this->team = Team::factory()->create(['supervisor_employee_id' => $supervisorEmployee->id]);
        $this->team->members()->attach([$tech1Employee->id, $tech2Employee->id]);

        $this->workflow = app(TicketWorkflowService::class);
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    private function person(): array
    {
        $email = fake()->unique()->safeEmail();
        $user = User::factory()->create([
            'email' => $email,
            'username' => 'u'.fake()->unique()->numerify('#######'),
            'status' => UserStatusEnum::ACTIVE,
        ]);
        $employee = Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE]);

        return [$employee, $user];
    }

    private function open(TaskPriorityEnum $priority = TaskPriorityEnum::Urgent): Ticket
    {
        return $this->workflow->open($this->requester, $this->team, $priority, 'Ana girişteki turnike çalışmıyor.');
    }

    private function openAssignedAndStarted(): Ticket
    {
        $ticket = $this->workflow->assign($this->open(), $this->tech1->employee, $this->supervisor);

        return $this->workflow->startWork($ticket, $this->tech1);
    }

    private function cycleOf(Ticket $ticket, int $no = 0)
    {
        return $no > 0
            ? $ticket->slaCycles()->where('cycle_no', $no)->sole()
            : $ticket->currentCycle()->first();
    }

    // ── Opening ─────────────────────────────────────────────────────────

    public function test_opening_starts_the_clock_when_the_ticket_reaches_the_team(): void
    {
        $ticket = $this->open();
        $cycle = $this->cycleOf($ticket);

        $this->assertSame(TaskStatusEnum::OPEN, $ticket->status);
        $this->assertSame($this->team->id, $ticket->team_id);
        $this->assertSame($this->team->ticket_type_id, $ticket->ticket_type_id);
        $this->assertSame(1, $cycle->cycle_no);
        $this->assertEquals(now()->addMinutes(15), $cycle->response_due_at);
        $this->assertEquals(now()->addMinutes(120), $cycle->resolution_due_at);
        $this->assertEquals($cycle->resolution_due_at, CarbonImmutable::instance($ticket->sla_deadline));
        $this->assertNull($ticket->employee_id);
        $this->assertStringStartsWith('TKT-', $ticket->ticket_no);
    }

    public function test_sla_targets_are_a_snapshot_of_the_team_policy(): void
    {
        $ticket = $this->open();
        $this->team->slaPolicyFor(TaskPriorityEnum::Urgent)->update(['resolution_minutes' => 9999]);

        $this->assertSame(120, $this->cycleOf($ticket)->resolution_minutes);
    }

    public function test_required_custom_fields_are_enforced(): void
    {
        $this->team->ticketType->update(['form_fields' => [
            ['key' => 'asset_no', 'label' => 'Demirbaş No', 'type' => FormFieldTypeEnum::Text->value, 'required' => true],
        ]]);

        $this->expectException(TicketWorkflowException::class);
        $this->workflow->open($this->requester, $this->team->fresh(), TaskPriorityEnum::Low, 'Açıklama');
    }

    public function test_inactive_team_cannot_receive_tickets(): void
    {
        $this->team->update(['is_active' => false]);

        $this->expectException(TicketWorkflowException::class);
        $this->open();
    }

    // ── Assignment ──────────────────────────────────────────────────────

    public function test_supervisor_assigns_a_team_member(): void
    {
        $ticket = $this->workflow->assign($this->open(), $this->tech1->employee, $this->supervisor);

        $this->assertSame(TaskStatusEnum::ASSIGNED, $ticket->status);
        $this->assertSame($this->tech1->employee->id, $ticket->employee_id);
        $this->assertEqualsWithDelta(1.0, $ticket->currentAssignment->remaining_ratio_at_start, 0.0001);
    }

    public function test_only_the_supervisor_assigns(): void
    {
        $this->expectException(TicketWorkflowException::class);
        $this->workflow->assign($this->open(), $this->tech1->employee, $this->tech2);
    }

    public function test_only_team_members_can_be_assigned(): void
    {
        $this->expectException(TicketWorkflowException::class);
        $this->workflow->assign($this->open(), $this->outsider->employee, $this->supervisor);
    }

    public function test_member_takes_a_ticket_from_the_pool(): void
    {
        $ticket = $this->workflow->selfAssign($this->open(), $this->tech2);

        $this->assertSame($this->tech2->employee->id, $ticket->employee_id);
        $this->assertTrue($ticket->currentAssignment->self_assigned);
    }

    public function test_pool_is_closed_when_the_team_disables_self_assign(): void
    {
        $this->team->update(['allow_self_assign' => false]);

        $this->expectException(TicketWorkflowException::class);
        $this->workflow->selfAssign($this->open(), $this->tech2);
    }

    public function test_outsider_cannot_take_from_the_pool(): void
    {
        $this->expectException(TicketWorkflowException::class);
        $this->workflow->selfAssign($this->open(), $this->outsider);
    }

    public function test_handing_over_never_resets_the_deadline(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $deadline = $this->cycleOf($ticket)->resolution_due_at;

        $this->travel(60)->minutes();
        $ticket = $this->workflow->assign($ticket, $this->tech2->employee, $this->supervisor);

        $this->assertEquals($deadline, $this->cycleOf($ticket)->resolution_due_at);
        $this->assertSame(TaskStatusEnum::ASSIGNED, $ticket->status);
        $this->assertSame(2, $ticket->assignments()->count());
        $this->assertEqualsWithDelta(0.5, $ticket->currentAssignment->remaining_ratio_at_start, 0.0001);
    }

    // ── Response SLA ────────────────────────────────────────────────────

    public function test_starting_work_stops_the_response_clock(): void
    {
        $ticket = $this->workflow->assign($this->open(), $this->tech1->employee, $this->supervisor);
        $this->travel(10)->minutes();
        $ticket = $this->workflow->startWork($ticket, $this->tech1);

        $cycle = $this->cycleOf($ticket);
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, $ticket->status);
        $this->assertEquals(now(), $cycle->responded_at);
        $this->assertFalse($cycle->response_breached);
    }

    public function test_late_start_breaches_the_response_sla(): void
    {
        $ticket = $this->workflow->assign($this->open(), $this->tech1->employee, $this->supervisor);
        $this->travel(20)->minutes();

        $this->assertTrue($this->cycleOf($this->workflow->startWork($ticket, $this->tech1))->response_breached);
    }

    public function test_only_the_assignee_starts_work(): void
    {
        $ticket = $this->workflow->assign($this->open(), $this->tech1->employee, $this->supervisor);

        $this->expectException(TicketWorkflowException::class);
        $this->workflow->startWork($ticket, $this->tech2);
    }

    // ── Hold ────────────────────────────────────────────────────────────

    public function test_hold_pauses_the_clock_and_moves_the_deadline(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $deadline = $this->cycleOf($ticket)->resolution_due_at;

        $this->travel(30)->minutes();
        $ticket = $this->workflow->hold($ticket, $this->tech1, HoldReasonEnum::AwaitingParts);
        $this->assertSame(TaskStatusEnum::ON_HOLD, $ticket->status);
        $this->assertSame(HoldReasonEnum::AwaitingParts, $ticket->hold_reason);

        $this->travel(500)->minutes(); // far past the original deadline while paused
        $ticket = $this->workflow->resume($ticket, $this->tech1);

        $cycle = $this->cycleOf($ticket);
        $this->assertSame(500, $cycle->paused_minutes);
        $this->assertEquals($deadline->addMinutes(500), $cycle->resolution_due_at);
        $this->assertFalse($cycle->resolution_breached);
        $this->assertSame(500, $ticket->total_on_hold_minutes);
    }

    // ── Resolution, confirmation, auto-close ────────────────────────────

    public function test_on_time_resolution_confirmed_by_the_requester(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $this->travel(90)->minutes();
        $ticket = $this->workflow->resolve($ticket, $this->tech1, 'Kart okuyucu değiştirildi.');

        $this->assertSame(TaskStatusEnum::RESOLVED, $ticket->status);
        $this->assertEquals(now()->addDays(3), $ticket->auto_close_at);

        $ticket = $this->workflow->confirm($ticket, $this->requester);
        $cycle = $this->cycleOf($ticket);

        $this->assertSame(TaskStatusEnum::CLOSED, $ticket->status);
        $this->assertSame(SlaCycleOutcomeEnum::OnTime, $cycle->outcome);
        $this->assertFalse($cycle->resolution_breached);
        $this->assertEquals(now()->addDays(7), $ticket->reopen_until);
        $this->assertNull($ticket->currentAssignment);
    }

    public function test_late_resolution_is_charged_to_the_person_holding_it(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $this->travel(150)->minutes();
        $ticket = $this->workflow->confirm($this->workflow->resolve($ticket, $this->tech1), $this->requester);

        $cycle = $this->cycleOf($ticket);
        $this->assertSame(SlaCycleOutcomeEnum::Breached, $cycle->outcome);
        $this->assertSame(BreachAttributionEnum::Person, $cycle->breach_attribution);
        $this->assertSame($this->tech1->employee->id, $cycle->breach_employee_id);
    }

    public function test_last_minute_handover_protects_the_new_holder(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $this->travel(100)->minutes(); // 20 of 120 min left = 16.7 %
        $ticket = $this->workflow->assign($ticket, $this->tech2->employee, $this->supervisor);
        $ticket = $this->workflow->startWork($ticket, $this->tech2);
        $this->travel(60)->minutes();
        $ticket = $this->workflow->resolve($ticket, $this->tech2);

        $cycle = $this->cycleOf($ticket);
        $this->assertTrue($cycle->resolution_breached);
        $this->assertSame(BreachAttributionEnum::Handover, $cycle->breach_attribution);
        $this->assertNull($cycle->breach_employee_id);
    }

    public function test_early_handover_makes_the_new_holder_responsible(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $this->travel(30)->minutes(); // 75 % left
        $ticket = $this->workflow->assign($ticket, $this->tech2->employee, $this->supervisor);
        $ticket = $this->workflow->startWork($ticket, $this->tech2);
        $this->travel(120)->minutes();
        $ticket = $this->workflow->resolve($ticket, $this->tech2);

        $cycle = $this->cycleOf($ticket);
        $this->assertSame(BreachAttributionEnum::Person, $cycle->breach_attribution);
        $this->assertSame($this->tech2->employee->id, $cycle->breach_employee_id);
    }

    public function test_breach_while_unassigned_is_a_dispatch_delay_not_personal(): void
    {
        $ticket = $this->open();
        $this->travel(130)->minutes();

        $this->assertTrue($this->workflow->flagResolutionBreach($this->cycleOf($ticket)));
        $this->assertFalse($this->workflow->flagResolutionBreach($this->cycleOf($ticket)), 'flags once');

        $cycle = $this->cycleOf($ticket);
        $this->assertSame(BreachAttributionEnum::Dispatch, $cycle->breach_attribution);
        $this->assertNull($cycle->breach_employee_id);
        $this->assertTrue($ticket->fresh()->sla_breached);
    }

    public function test_requester_rejects_the_resolution_and_waiting_time_is_not_charged(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $this->travel(60)->minutes();
        $ticket = $this->workflow->resolve($ticket, $this->tech1);
        $deadline = $this->cycleOf($ticket)->resolution_due_at;

        $this->travel(300)->minutes(); // requester answers 5 hours later
        $ticket = $this->workflow->rejectResolution($ticket, $this->requester, 'Turnike hâlâ kart okumuyor.');

        $cycle = $this->cycleOf($ticket);
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, $ticket->status);
        $this->assertSame(1, $cycle->cycle_no, 'same cycle carries on');
        $this->assertSame(1, $cycle->resolution_rejections);
        $this->assertEquals($deadline->addMinutes(300), $cycle->resolution_due_at);
        $this->assertNull($cycle->resolved_at);

        $this->travel(30)->minutes();
        $ticket = $this->workflow->confirm($this->workflow->resolve($ticket, $this->tech1), $this->requester);
        $this->assertSame(SlaCycleOutcomeEnum::OnTime, $this->cycleOf($ticket)->outcome);
    }

    public function test_rejecting_a_resolution_requires_a_reason(): void
    {
        $ticket = $this->workflow->resolve($this->openAssignedAndStarted(), $this->tech1);

        $this->expectException(TicketWorkflowException::class);
        $this->workflow->rejectResolution($ticket, $this->requester, '  ');
    }

    public function test_unconfirmed_resolution_closes_automatically(): void
    {
        $ticket = $this->workflow->resolve($this->openAssignedAndStarted(), $this->tech1);

        try {
            $this->workflow->autoClose($ticket);
            $this->fail('closed before the deadline');
        } catch (TicketWorkflowException) {
        }

        $this->travel(3)->days();
        $ticket = $this->workflow->autoClose($ticket->fresh());

        $this->assertSame(TaskStatusEnum::CLOSED, $ticket->status);
        $this->assertNull($ticket->closed_by);
        $this->assertSame(SlaCycleOutcomeEnum::OnTime, $this->cycleOf($ticket)->outcome);
    }

    // ── Reopen ──────────────────────────────────────────────────────────

    public function test_reopen_starts_a_new_cycle_with_the_resolver(): void
    {
        $ticket = $this->workflow->confirm($this->workflow->resolve($this->openAssignedAndStarted(), $this->tech1), $this->requester);
        $this->travel(2)->days();

        $ticket = $this->workflow->reopen($ticket, $this->requester, 'Sorun tekrarladı.');

        $this->assertSame(TaskStatusEnum::ASSIGNED, $ticket->status);
        $this->assertSame($this->tech1->employee->id, $ticket->employee_id);
        $this->assertSame(SlaCycleOutcomeEnum::OnTime, $this->cycleOf($ticket, 1)->outcome, 'first cycle result is kept');
        $second = $this->cycleOf($ticket);
        $this->assertSame(2, $second->cycle_no);
        $this->assertEquals(now()->addMinutes(120), $second->resolution_due_at);
        $this->assertNotNull($ticket->reopened_at);
    }

    public function test_reopen_window_is_enforced(): void
    {
        $ticket = $this->workflow->confirm($this->workflow->resolve($this->openAssignedAndStarted(), $this->tech1), $this->requester);
        $this->travel(8)->days();

        $this->expectException(TicketWorkflowException::class);
        $this->workflow->reopen($ticket, $this->requester, 'Geç kaldım.');
    }

    // ── Cancel, reject, transfer, priority ──────────────────────────────

    public function test_requester_cancels_before_work_starts(): void
    {
        $ticket = $this->workflow->cancel($this->open(), $this->requester, 'Yanlışlıkla açtım.');

        $this->assertSame(TaskStatusEnum::CANCELLED, $ticket->status);
        $this->assertSame(SlaCycleOutcomeEnum::Cancelled, $this->cycleOf($ticket)->outcome);
    }

    public function test_after_work_starts_only_the_supervisor_cancels(): void
    {
        $ticket = $this->openAssignedAndStarted();

        try {
            $this->workflow->cancel($ticket, $this->requester, 'Vazgeçtim.');
            $this->fail('requester cancelled a ticket in progress');
        } catch (TicketWorkflowException) {
        }

        $ticket = $this->workflow->cancel($ticket->fresh(), $this->supervisor, 'Talep sahibi vazgeçti.');
        $this->assertSame(TaskStatusEnum::CANCELLED, $ticket->status);
    }

    public function test_cancelling_after_a_breach_keeps_the_breach_visible(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $this->travel(200)->minutes();
        $ticket = $this->workflow->cancel($ticket, $this->supervisor, 'İş iptal edildi.');

        $cycle = $this->cycleOf($ticket);
        $this->assertSame(SlaCycleOutcomeEnum::Cancelled, $cycle->outcome);
        $this->assertTrue($cycle->resolution_breached);
        $this->assertFalse($cycle->outcome->countsForPerformance());
    }

    public function test_supervisor_rejects_a_duplicate(): void
    {
        $ticket = $this->workflow->reject($this->open(), $this->supervisor, RejectionReasonEnum::Duplicate);

        $this->assertSame(TaskStatusEnum::REJECTED, $ticket->status);
        $this->assertSame('duplicate', $ticket->closure_reason);
        $this->assertSame(SlaCycleOutcomeEnum::Rejected, $this->cycleOf($ticket)->outcome);
    }

    public function test_transfer_closes_the_old_cycle_and_starts_the_new_team_clock(): void
    {
        $target = Team::factory()->create();
        $ticket = $this->openAssignedAndStarted();
        $this->travel(100)->minutes();

        $ticket = $this->workflow->transfer($ticket, $target, $this->supervisor, 'Elektrik ekibinin işi.');

        $old = $this->cycleOf($ticket, 1);
        $new = $this->cycleOf($ticket);
        $this->assertSame(SlaCycleOutcomeEnum::Transferred, $old->outcome);
        $this->assertFalse($old->resolution_breached);
        $this->assertSame($target->id, $new->team_id);
        $this->assertSame($target->id, $ticket->team_id);
        $this->assertSame($target->ticket_type_id, $ticket->ticket_type_id);
        $this->assertSame(TaskStatusEnum::OPEN, $ticket->status);
        $this->assertNull($ticket->employee_id);
        $this->assertEquals(now()->addMinutes(120), $new->resolution_due_at);
    }

    public function test_transfer_after_the_deadline_keeps_the_breach_on_who_held_it(): void
    {
        $target = Team::factory()->create();
        $ticket = $this->openAssignedAndStarted(); // Urgent: 120 min, tech1 holds it
        $this->travel(150)->minutes();

        $ticket = $this->workflow->transfer($ticket, $target, $this->supervisor, 'Elektrik ekibinin işi.');

        $old = $this->cycleOf($ticket, 1);
        $this->assertSame(SlaCycleOutcomeEnum::Breached, $old->outcome);
        $this->assertTrue($old->resolution_breached);
        $this->assertSame(BreachAttributionEnum::Person, $old->breach_attribution);
        $this->assertSame($this->tech1->employee->id, $old->breach_employee_id);
        $this->assertFalse($this->cycleOf($ticket)->resolution_breached, 'the new team starts with a clean clock');
    }

    public function test_reassigning_after_the_deadline_keeps_the_breach_on_who_held_it(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $this->travel(150)->minutes();

        $ticket = $this->workflow->assign($ticket, $this->tech2->employee, $this->supervisor);
        $ticket = $this->workflow->resolve($this->workflow->startWork($ticket, $this->tech2), $this->tech2, 'Tamamlandı.');

        $cycle = $this->cycleOf($ticket);
        $this->assertTrue($cycle->resolution_breached);
        $this->assertSame($this->tech1->employee->id, $cycle->breach_employee_id);
    }

    // ── Isolation from the legacy flow ──────────────────────────────────

    public function test_legacy_observer_leaves_team_tickets_alone(): void
    {
        $ticket = $this->open();
        $this->travel(500)->minutes();

        $ticket->update(['description' => 'Güncellendi']);

        $this->assertFalse($ticket->fresh()->sla_breached);
    }

    public function test_remaining_time_is_working_time_not_wall_clock(): void
    {
        $office = WorkCalendar::factory()->create(); // Mon–Fri 08:00–18:00
        $this->team->update(['work_calendar_id' => $office->id]);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 19:12:00')); // Monday evening
        $cycle = $this->cycleOf($this->open()); // Urgent: 120 working minutes

        // Overnight nothing is used, even though the deadline is ~14 hours away.
        $this->assertSame(120, $this->workflow->remainingWorkingMinutes($cycle));

        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:30:00'));
        $this->assertSame(30, $this->workflow->remainingWorkingMinutes($cycle));

        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:45:00'));
        $this->assertSame(-45, $this->workflow->remainingWorkingMinutes($cycle));
        $this->assertSame('45 dk gecikti', TeamTicketResource::remainingLabel(-45));
        $this->assertSame('2 sa 5 dk kaldı', TeamTicketResource::remainingLabel(125));
        $this->assertSame('26 sa kaldı', TeamTicketResource::remainingLabel(1560));
    }

    public function test_paused_ticket_keeps_its_remaining_time(): void
    {
        $ticket = $this->openAssignedAndStarted();
        $this->travel(30)->minutes();
        $ticket = $this->workflow->hold($ticket, $this->tech1, HoldReasonEnum::AwaitingParts);

        $this->travel(300)->minutes();

        $this->assertSame(90, $this->workflow->remainingWorkingMinutes($this->cycleOf($ticket)));
    }

    public function test_office_hours_team_counts_only_working_time(): void
    {
        $office = WorkCalendar::factory()->create(); // Mon–Fri 08:00–18:00
        $this->team->update(['work_calendar_id' => $office->id]);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 17:30:00')); // Friday

        $cycle = $this->cycleOf($this->open());

        // Urgent: 15 min response, 120 min resolution.
        // Friday 17:30–18:00 = 30 min, the remaining 90 min run from Monday 08:00.
        $this->assertEquals(CarbonImmutable::parse('2026-11-20 17:45:00'), $cycle->response_due_at);
        $this->assertEquals(CarbonImmutable::parse('2026-11-23 09:30:00'), $cycle->resolution_due_at);
    }
}
