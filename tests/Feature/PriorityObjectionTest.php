<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TeamTicketEventEnum;
use App\Enums\UserStatusEnum;
use App\Exceptions\TicketWorkflowException;
use App\Filament\Resources\TeamTicketResource\Pages\ViewTeamTicket;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TeamTicketNotification;
use App\Services\FcmService;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The requester hears about a priority change by mail and may object once to
 * a lowering; the supervisor accepts (priority restored) or rejects.
 */
class PriorityObjectionTest extends TestCase
{
    use RefreshDatabase;

    private TicketWorkflowService $workflow;

    private Team $team;

    private User $requester;

    private User $supervisor;

    private User $tech;

    /**
     * @var array<int, int> user ids that received an FCM push
     */
    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        Notification::fake();
        $this->seed(PermissionSeeder::class);

        $this->mock(FcmService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->andReturnUsing(function (User $user): void {
                $this->pushed[] = $user->id;
            });
            $mock->shouldReceive('sendToUsers')->andReturnNull();
        });

        $this->requester = $this->person()[1];
        [$supervisorEmployee, $this->supervisor] = $this->person();
        [$techEmployee, $this->tech] = $this->person();
        $this->actingAs($this->requester);

        $this->team = Team::factory()->create(['supervisor_employee_id' => $supervisorEmployee->id]);
        $this->team->members()->attach($techEmployee->id);
        $this->workflow = app(TicketWorkflowService::class);
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    private function person(): array
    {
        $email = fake()->unique()->safeEmail();
        $user = User::factory()->create(['email' => $email, 'username' => 'u'.fake()->unique()->numerify('#######'), 'status' => UserStatusEnum::ACTIVE]);
        $user->givePermissionTo(['view_any_ticket', 'view_ticket', 'create_ticket']);

        return [Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE]), $user];
    }

    private function lowered(): Ticket
    {
        $ticket = $this->workflow->open($this->requester, $this->team, TaskPriorityEnum::Urgent, 'Ana giriş turnikesi çalışmıyor.');

        return $this->workflow->changePriority($ticket, TaskPriorityEnum::Medium, $this->supervisor, 'Yan giriş açık, acil değil.');
    }

    /**
     * @param  array<int, string>  $channels
     */
    private function assertNotified(User $user, TeamTicketEventEnum $event, array $channels): void
    {
        Notification::assertSentTo(
            $user,
            TeamTicketNotification::class,
            fn (TeamTicketNotification $n): bool => $n->event === $event && $n->channels === $channels,
        );
    }

    public function test_requester_is_mailed_when_the_priority_changes(): void
    {
        $this->lowered();

        $this->assertNotified($this->requester, TeamTicketEventEnum::PriorityChanged, ['database', 'mail']);
    }

    public function test_lowering_opens_an_objection_and_raising_does_not(): void
    {
        $ticket = $this->lowered();

        $this->assertSame(TaskPriorityEnum::Urgent, $ticket->priority_lowered_from);
        $this->assertTrue($this->workflow->canObjectToPriority($ticket));

        $ticket = $this->workflow->changePriority($ticket, TaskPriorityEnum::High, $this->supervisor, 'Kalabalık arttı.');

        $this->assertNull($ticket->priority_lowered_from);
        $this->assertFalse($this->workflow->canObjectToPriority($ticket));
    }

    public function test_requester_objects_once_and_the_supervisor_must_act(): void
    {
        $ticket = $this->workflow->objectToPriority($this->lowered(), $this->requester, 'Ziyaretçiler kapıda bekliyor.');

        $this->assertNotNull($ticket->priority_objected_at);
        $this->assertTrue($this->workflow->hasOpenPriorityObjection($ticket));
        $this->assertNotified($this->supervisor, TeamTicketEventEnum::PriorityObjected, ['database', 'mail']);
        $this->assertContains($this->supervisor->id, $this->pushed);
        $this->assertStringContainsString('Ziyaretçiler kapıda bekliyor.', TicketStatusHistory::where('ticket_id', $ticket->id)->latest('id')->value('note'));

        $this->expectException(TicketWorkflowException::class);
        $this->workflow->objectToPriority($ticket, $this->requester, 'Tekrar.');
    }

    public function test_only_the_requester_may_object(): void
    {
        $this->expectException(TicketWorkflowException::class);
        $this->workflow->objectToPriority($this->lowered(), $this->tech, 'Bence acil.');
    }

    public function test_objection_needs_a_reason(): void
    {
        $this->expectException(TicketWorkflowException::class);
        $this->workflow->objectToPriority($this->lowered(), $this->requester, '  ');
    }

    public function test_accepting_restores_the_priority_and_its_targets(): void
    {
        $ticket = $this->workflow->objectToPriority($this->lowered(), $this->requester, 'Ziyaretçiler kapıda bekliyor.');
        $this->travel(30)->minutes();

        $ticket = $this->workflow->decidePriorityObjection($ticket, $this->supervisor, true, 'Haklısınız.');

        $cycle = $ticket->currentCycle()->first();
        $this->assertSame(TaskPriorityEnum::Urgent, $ticket->priority);
        $this->assertEquals($cycle->started_at->addMinutes(120), $cycle->resolution_due_at);
        $this->assertNull($ticket->priority_lowered_from);
        $this->assertNull($ticket->priority_objected_at);
        $this->assertNotified($this->requester, TeamTicketEventEnum::PriorityObjectionDecided, ['database', 'mail']);
    }

    public function test_rejecting_keeps_the_priority_and_ends_the_objection(): void
    {
        $ticket = $this->workflow->objectToPriority($this->lowered(), $this->requester, 'Ziyaretçiler kapıda bekliyor.');

        $ticket = $this->workflow->decidePriorityObjection($ticket, $this->supervisor, false, 'Yan giriş yeterli.');

        $this->assertSame(TaskPriorityEnum::Medium, $ticket->priority);
        $this->assertFalse($this->workflow->canObjectToPriority($ticket));
        $this->assertFalse($this->workflow->hasOpenPriorityObjection($ticket));
        $this->assertNotified($this->requester, TeamTicketEventEnum::PriorityObjectionDecided, ['database', 'mail']);
    }

    public function test_decision_needs_an_open_objection_and_the_supervisor(): void
    {
        $ticket = $this->lowered();

        try {
            $this->workflow->decidePriorityObjection($ticket, $this->supervisor, true, 'Kabul.');
            $this->fail('No objection was made.');
        } catch (TicketWorkflowException) {
        }

        $ticket = $this->workflow->objectToPriority($ticket, $this->requester, 'Acil.');

        $this->expectException(TicketWorkflowException::class);
        $this->workflow->decidePriorityObjection($ticket, $this->requester, true, 'Kendim kabul ediyorum.');
    }

    public function test_objection_through_the_detail_page(): void
    {
        $ticket = $this->lowered();

        $this->actingAs($this->requester);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->assertActionHidden('decidePriorityObjection')
            ->callAction('objectPriority', data: ['reason' => 'Ziyaretçiler kapıda bekliyor.'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('objectPriority');

        $this->actingAs($this->supervisor);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->assertActionHidden('objectPriority')
            ->callAction('decidePriorityObjection', data: ['decision' => 'accept', 'reason' => 'Haklısınız.'])
            ->assertHasNoActionErrors();

        $this->assertSame(TaskPriorityEnum::Urgent, $ticket->fresh()->priority);
    }
}
