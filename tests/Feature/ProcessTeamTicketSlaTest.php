<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\BreachAttributionEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\UserStatusEnum;
use App\Events\TeamTicketSlaBreached;
use App\Events\TeamTicketSlaWarning;
use App\Jobs\ProcessTeamTicketSla;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Urgent on a 7/24 team: 15 min response, 120 min resolution.
 */
class ProcessTeamTicketSlaTest extends TestCase
{
    use RefreshDatabase;

    private TicketWorkflowService $workflow;

    private User $requester;

    private User $supervisor;

    private User $tech;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        $this->requester = $this->person()[1];
        [$supervisorEmployee, $this->supervisor] = $this->person();
        [$techEmployee, $this->tech] = $this->person();
        $this->actingAs($this->requester);

        $this->team = Team::factory()->create(['supervisor_employee_id' => $supervisorEmployee->id]);
        $this->team->members()->attach($techEmployee->id);
        $this->workflow = app(TicketWorkflowService::class);

        Event::fake([TeamTicketSlaWarning::class, TeamTicketSlaBreached::class]);
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    private function person(): array
    {
        $email = fake()->unique()->safeEmail();
        $user = User::factory()->create(['email' => $email, 'username' => 'u'.fake()->unique()->numerify('#######'), 'status' => UserStatusEnum::ACTIVE]);

        return [Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE]), $user];
    }

    private function open(): Ticket
    {
        return $this->workflow->open($this->requester, $this->team, TaskPriorityEnum::Urgent, 'Kasa sistemi kapandı.');
    }

    private function runJob(): void
    {
        app()->call([new ProcessTeamTicketSla, 'handle']);
    }

    public function test_unanswered_ticket_gets_a_response_breach(): void
    {
        $ticket = $this->open();

        $this->travel(10)->minutes();
        $this->runJob();
        $this->assertFalse($ticket->currentCycle->response_breached);

        $this->travel(10)->minutes();
        $this->runJob();
        $this->assertTrue($ticket->currentCycle()->first()->response_breached);
    }

    public function test_warning_fires_once_after_eighty_percent(): void
    {
        $ticket = $this->workflow->startWork($this->workflow->assign($this->open(), $this->tech->employee, $this->supervisor), $this->tech);

        $this->travel(90)->minutes(); // 75 %
        $this->runJob();
        Event::assertNotDispatched(TeamTicketSlaWarning::class);

        $this->travel(10)->minutes(); // 83 %
        $this->runJob();
        $this->runJob();

        Event::assertDispatchedTimes(TeamTicketSlaWarning::class, 1);
        $this->assertNotNull($ticket->currentCycle()->first()->warning_sent_at);
    }

    public function test_paused_time_does_not_count_towards_the_warning(): void
    {
        $ticket = $this->workflow->startWork($this->workflow->assign($this->open(), $this->tech->employee, $this->supervisor), $this->tech);
        $this->travel(60)->minutes();
        $this->workflow->hold($ticket, $this->tech, HoldReasonEnum::AwaitingVendor);

        $this->travel(600)->minutes();
        $this->runJob();

        Event::assertNotDispatched(TeamTicketSlaWarning::class);
        Event::assertNotDispatched(TeamTicketSlaBreached::class);
    }

    public function test_breach_is_flagged_and_announced_once(): void
    {
        $ticket = $this->workflow->assign($this->open(), $this->tech->employee, $this->supervisor);

        $this->travel(121)->minutes();
        $this->runJob();
        $this->runJob();

        Event::assertDispatchedTimes(TeamTicketSlaBreached::class, 1);
        $cycle = $ticket->currentCycle()->first();
        $this->assertTrue($cycle->resolution_breached);
        $this->assertSame(BreachAttributionEnum::Person, $cycle->breach_attribution);
        $this->assertSame($this->tech->employee->id, $cycle->breach_employee_id);
        $this->assertTrue($ticket->fresh()->sla_breached);
    }

    public function test_unconfirmed_resolutions_are_closed_after_the_configured_days(): void
    {
        $ticket = $this->workflow->startWork($this->workflow->assign($this->open(), $this->tech->employee, $this->supervisor), $this->tech);
        $ticket = $this->workflow->resolve($ticket, $this->tech);

        $this->travel(2)->days();
        $this->runJob();
        $this->assertSame(TaskStatusEnum::RESOLVED, $ticket->fresh()->status);

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->runJob();
        $this->assertSame(TaskStatusEnum::CLOSED, $ticket->fresh()->status);
    }
}
