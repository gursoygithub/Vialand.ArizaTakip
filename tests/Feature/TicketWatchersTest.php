<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\FormFieldTypeEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\LocationRequirementEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TeamTicketEventEnum;
use App\Enums\UserStatusEnum;
use App\Exceptions\TicketWorkflowException;
use App\Filament\Resources\TeamTicketResource;
use App\Filament\Resources\TeamTicketResource\Pages\CreateTeamTicket;
use App\Filament\Resources\TeamTicketResource\Pages\ListTeamTickets;
use App\Filament\Resources\TeamTicketResource\Pages\ViewTeamTicket;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Notifications\TeamTicketNotification;
use App\Notifications\TicketCommentNotification;
use App\Services\FcmService;
use App\Services\TeamTicketNotifier;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class TicketWatchersTest extends TestCase
{
    use RefreshDatabase;

    private TicketType $type;

    private Team $team;

    private User $requester;

    private User $supervisor;

    private User $tech;

    private User $manager;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->mock(FcmService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser', 'sendToUsers')->andReturnNull();
        });

        $this->requester = $this->person()[1];
        [$supervisorEmployee, $this->supervisor] = $this->person();
        [$techEmployee, $this->tech] = $this->person();
        $this->manager = $this->person()[1];
        $this->outsider = $this->person()[1];

        $this->type = TicketType::factory()->create([
            'name' => 'IT Destek',
            'location_requirement' => LocationRequirementEnum::Hidden,
            'form_fields' => [
                ['key' => 'device_kind', 'label' => 'Konu', 'type' => FormFieldTypeEnum::Select->value, 'options' => ['Bilgisayar', 'Yazılım / Hesap'], 'cc_required_options' => ['Yazılım / Hesap'], 'required' => true],
            ],
        ]);
        $this->team = Team::factory()->create([
            'ticket_type_id' => $this->type->id,
            'supervisor_employee_id' => $supervisorEmployee->id,
        ]);
        $this->team->members()->attach($techEmployee->id);

        $this->actingAs($this->requester);
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
        $user->givePermissionTo(['view_any_team::ticket', 'view_team::ticket', 'create_team::ticket']);

        return [Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE]), $user];
    }

    /**
     * @param  array<int, int>  $watcherIds
     */
    private function open(array $watcherIds = [], string $topic = 'Bilgisayar'): Ticket
    {
        return app(TicketWorkflowService::class)->open(
            $this->requester, $this->team, TaskPriorityEnum::Medium, 'Bilgisayar açılmıyor.', ['device_kind' => $topic], [], $watcherIds,
        );
    }

    private function createForm(array $data): Testable
    {
        return Livewire::test(CreateTeamTicket::class)
            ->fillForm(['ticket_type_id' => $this->type->id])
            ->fillForm(array_merge(['team_id' => $this->team->id, 'description' => 'Muhasebe programına yetki gerekiyor.'], $data))
            ->call('create');
    }

    // ── Creating ────────────────────────────────────────────────────────

    public function test_requester_adds_people_to_keep_informed(): void
    {
        Notification::fake();

        $this->createForm(['custom_fields' => ['device_kind' => 'Bilgisayar'], 'watchers' => [$this->manager->employee->id]])
            ->assertHasNoFormErrors();

        $ticket = Ticket::whereNotNull('team_id')->sole();
        $this->assertSame([$this->manager->employee->id], $ticket->watchers()->pluck('employees.id')->all());
        $this->assertSame($this->requester->id, $ticket->watchers()->first()->pivot->added_by);
    }

    public function test_cc_is_required_for_marked_topics_only(): void
    {
        $this->createForm(['custom_fields' => ['device_kind' => 'Yazılım / Hesap'], 'watchers' => []])
            ->assertHasFormErrors(['watchers' => 'required']);

        Notification::fake();
        $this->createForm(['custom_fields' => ['device_kind' => 'Bilgisayar'], 'watchers' => []])
            ->assertHasNoFormErrors();
    }

    public function test_cc_can_be_required_for_every_request_of_a_type(): void
    {
        $this->type->update(['cc_required' => true]);

        $this->expectException(TicketWorkflowException::class);
        $this->expectExceptionMessage(__('ui.workflow_watchers_required'));

        $this->open();
    }

    public function test_person_without_email_cannot_be_kept_informed(): void
    {
        $noEmail = Employee::factory()->create(['name' => 'MAİLSİZ KİŞİ', 'email' => '', 'status' => ActiveStatusEnum::ACTIVE]);

        try {
            $this->open([$noEmail->id]);
            $this->fail('A CC without e-mail must be refused.');
        } catch (TicketWorkflowException $exception) {
            $this->assertSame('watchers', $exception->field);
            $this->assertStringContainsString('MAİLSİZ KİŞİ', $exception->getMessage());
        }

        $this->assertSame(0, Ticket::count());
    }

    public function test_requester_is_never_their_own_cc(): void
    {
        Notification::fake();

        $ticket = $this->open([$this->requester->employee->id, $this->manager->employee->id]);

        $this->assertSame([$this->manager->employee->id], $ticket->watchers()->pluck('employees.id')->all());
    }

    public function test_cc_starts_empty_on_every_new_request(): void
    {
        Notification::fake();
        $this->open([$this->manager->employee->id]);

        Livewire::test(CreateTeamTicket::class)
            ->assertFormSet(['watchers' => []]);
    }

    // ── Seeing and taking part ──────────────────────────────────────────

    public function test_cc_person_sees_the_whole_request_and_may_add_notes_only(): void
    {
        Notification::fake();
        $ticket = $this->open([$this->manager->employee->id]);

        $this->assertTrue(Ticket::visibleInTeamWorkflow($this->manager)->whereKey($ticket->id)->exists());
        $this->assertFalse(Ticket::visibleInTeamWorkflow($this->outsider)->whereKey($ticket->id)->exists());

        $this->actingAs($this->manager);
        $this->get(TeamTicketResource::getUrl('view', ['record' => $ticket]))
            ->assertOk()
            ->assertSee(__('ui.watchers'))
            ->assertSee($this->manager->employee->name);

        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->assertActionVisible('addComment')
            ->assertActionHidden('assign')
            ->assertActionHidden('selfAssign')
            ->assertActionHidden('cancel');

        Livewire::test(ListTeamTickets::class)
            ->set('activeTab', 'watching')
            ->assertCanSeeTableRecords([$ticket]);
    }

    public function test_notes_reach_the_cc_person(): void
    {
        Notification::fake();
        $ticket = $this->open([$this->manager->employee->id]);

        app(TicketService::class)->addComment($ticket, $this->requester, 'Yöneticim bilgilendirildi.');

        Notification::assertSentTo($this->manager, TicketCommentNotification::class);
    }

    // ── Notifications ───────────────────────────────────────────────────

    public function test_cc_person_is_mailed_on_opening_and_outcome_and_belled_in_between(): void
    {
        Notification::fake();
        $workflow = app(TicketWorkflowService::class);

        $ticket = $this->open([$this->manager->employee->id]);
        $ticket = $workflow->assign($ticket, $this->tech->employee, $this->supervisor);
        $ticket = $workflow->startWork($ticket, $this->tech);
        $workflow->resolve($ticket, $this->tech, 'Yetki verildi.');

        $channels = fn (TeamTicketEventEnum $event): ?array => Notification::sent($this->manager, TeamTicketNotification::class)
            ->first(fn (TeamTicketNotification $notification): bool => $notification->event === $event)?->channels;

        $this->assertSame(['database', 'mail'], $channels(TeamTicketEventEnum::Opened));
        $this->assertSame(['database'], $channels(TeamTicketEventEnum::Assigned));
        $this->assertSame(['database', 'mail'], $channels(TeamTicketEventEnum::Resolved));
    }

    public function test_sla_matters_stay_inside_the_team(): void
    {
        Notification::fake();
        $ticket = $this->open([$this->manager->employee->id]);

        app(TeamTicketNotifier::class)->slaBreached($ticket->currentCycle);

        Notification::assertNotSentTo($this->manager, TeamTicketNotification::class,
            fn (TeamTicketNotification $notification): bool => $notification->event === TeamTicketEventEnum::SlaBreached);
    }

    public function test_cc_person_without_an_account_still_gets_the_mails(): void
    {
        Notification::fake();
        $neverLoggedIn = Employee::factory()->create(['email' => 'yonetici@example.com', 'status' => ActiveStatusEnum::ACTIVE]);

        $this->open([$neverLoggedIn->id]);

        Notification::assertSentOnDemand(TeamTicketNotification::class,
            fn (TeamTicketNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'yonetici@example.com'
                && $notification->event === TeamTicketEventEnum::Opened
                && $channels === ['mail']);
    }

    // ── Waiting for an approval ─────────────────────────────────────────

    public function test_waiting_for_an_approval_pauses_the_clock_and_needs_a_note(): void
    {
        Notification::fake();
        $workflow = app(TicketWorkflowService::class);
        $ticket = $workflow->startWork($workflow->assign($this->open(), $this->tech->employee, $this->supervisor), $this->tech);

        try {
            $workflow->hold($ticket, $this->tech, HoldReasonEnum::AwaitingApproval);
            $this->fail('A note is required.');
        } catch (TicketWorkflowException $exception) {
            $this->assertSame(__('ui.workflow_reason_required'), $exception->getMessage());
        }

        $ticket = $workflow->hold($ticket->fresh(), $this->tech, HoldReasonEnum::AwaitingApproval, 'Finans müdürünün onayı bekleniyor.');

        $this->assertSame(TaskStatusEnum::ON_HOLD, $ticket->status);
        $this->assertSame(HoldReasonEnum::AwaitingApproval, $ticket->hold_reason);
        $this->assertNotNull($ticket->currentCycle->paused_since);
    }
}
