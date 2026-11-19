<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\RejectionReasonEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TeamTicketEventEnum;
use App\Enums\UserStatusEnum;
use App\Jobs\ProcessTeamTicketSla;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketMute;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\TeamTicketNotification;
use App\Services\FcmService;
use App\Services\TeamTicketNotifier;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The team-ticket notification matrix (agreement §8).
 */
class TeamTicketNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $supervisor;

    private User $tech;

    private Team $team;

    /**
     * @var array<int, string> user ids that received an FCM push
     */
    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        Notification::fake();

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

    private function workflow(): TicketWorkflowService
    {
        return app(TicketWorkflowService::class);
    }

    private function open(): Ticket
    {
        return $this->workflow()->open($this->requester, $this->team, TaskPriorityEnum::Urgent, 'Klima su akıtıyor.');
    }

    private function assigned(): Ticket
    {
        return $this->workflow()->assign($this->open(), $this->tech->employee, $this->supervisor);
    }

    private function started(): Ticket
    {
        return $this->workflow()->startWork($this->assigned(), $this->tech);
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

    private function assertNotNotified(User $user, TeamTicketEventEnum $event): void
    {
        Notification::assertNotSentTo(
            $user,
            TeamTicketNotification::class,
            fn (TeamTicketNotification $n): bool => $n->event === $event,
        );
    }

    // ── Matrix rows ─────────────────────────────────────────────────────

    public function test_opening_alerts_the_supervisor_and_confirms_to_the_requester(): void
    {
        $this->open();

        $this->assertNotified($this->supervisor, TeamTicketEventEnum::Opened, ['database', 'mail']);
        $this->assertNotified($this->requester, TeamTicketEventEnum::Opened, ['database']);
        $this->assertContains($this->supervisor->id, $this->pushed);
        $this->assertNotContains($this->requester->id, $this->pushed);
    }

    public function test_assignment_alerts_the_assignee_and_informs_the_requester(): void
    {
        $this->assigned();

        $this->assertNotified($this->tech, TeamTicketEventEnum::Assigned, ['database', 'mail']);
        $this->assertNotified($this->requester, TeamTicketEventEnum::Assigned, ['database']);
        $this->assertNotNotified($this->supervisor, TeamTicketEventEnum::Assigned);
        $this->assertContains($this->tech->id, $this->pushed);
    }

    public function test_starting_work_informs_the_requester(): void
    {
        $this->started();

        $this->assertNotified($this->requester, TeamTicketEventEnum::Started, ['database']);
    }

    public function test_hold_waiting_for_the_requester_is_an_action_for_them(): void
    {
        $this->workflow()->hold($this->started(), $this->tech, HoldReasonEnum::AwaitingRequester, 'Hangi kat?');

        $this->assertNotified($this->requester, TeamTicketEventEnum::Held, ['database', 'mail']);
        $this->assertNotified($this->supervisor, TeamTicketEventEnum::Held, ['database', 'mail']);
    }

    public function test_other_holds_only_inform_the_requester(): void
    {
        $this->workflow()->hold($this->started(), $this->tech, HoldReasonEnum::AwaitingParts);

        $this->assertNotified($this->requester, TeamTicketEventEnum::Held, ['database']);
    }

    public function test_resolution_asks_the_requester_to_confirm(): void
    {
        $this->workflow()->resolve($this->started(), $this->tech, 'Filtre temizlendi.');

        $this->assertNotified($this->requester, TeamTicketEventEnum::Resolved, ['database', 'mail']);
        $this->assertContains($this->requester->id, $this->pushed);
    }

    public function test_requester_without_email_gets_the_bell_and_the_skip_is_logged(): void
    {
        $this->requester->update(['email' => '']);
        Log::spy();

        $ticket = $this->workflow()->resolve($this->started(), $this->tech);

        $this->assertNotified($this->requester, TeamTicketEventEnum::Resolved, ['database']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Mail skipped')
                && $context['ticket_no'] === $ticket->ticket_no
                && $context['user_id'] === $this->requester->id)
            ->once();
    }

    public function test_rejected_resolution_goes_back_to_the_assignee(): void
    {
        $ticket = $this->workflow()->resolve($this->started(), $this->tech);
        $this->workflow()->rejectResolution($ticket, $this->requester, 'Hâlâ akıtıyor.');

        $this->assertNotified($this->tech, TeamTicketEventEnum::ResolutionRejected, ['database', 'mail']);
        $this->assertNotified($this->supervisor, TeamTicketEventEnum::ResolutionRejected, ['database']);
    }

    public function test_transfer_alerts_the_new_supervisor_and_informs_the_old_one(): void
    {
        [$newSupervisorEmployee, $newSupervisor] = $this->person();
        $target = Team::factory()->create(['supervisor_employee_id' => $newSupervisorEmployee->id]);

        $this->workflow()->transfer($this->open(), $target, $this->supervisor, 'Elektrik işi.');

        $this->assertNotified($newSupervisor, TeamTicketEventEnum::Transferred, ['database', 'mail']);
        $this->assertNotified($this->requester, TeamTicketEventEnum::Transferred, ['database']);
        $this->assertNotNotified($this->supervisor, TeamTicketEventEnum::Transferred); // actor
    }

    public function test_rejection_is_mailed_to_the_requester(): void
    {
        $this->workflow()->reject($this->open(), $this->supervisor, RejectionReasonEnum::Duplicate);

        $this->assertNotified($this->requester, TeamTicketEventEnum::Rejected, ['database', 'mail']);
    }

    public function test_actor_is_never_notified_of_their_own_cancellation(): void
    {
        $this->workflow()->cancel($this->assigned(), $this->requester, 'Gerek kalmadı.');

        $this->assertNotNotified($this->requester, TeamTicketEventEnum::Cancelled);
        $this->assertNotified($this->tech, TeamTicketEventEnum::Cancelled, ['database']);
    }

    // ── Preferences, mutes, rollback ────────────────────────────────────

    public function test_mute_silences_info_but_not_actions(): void
    {
        $ticket = $this->started();
        TicketMute::create(['ticket_id' => $ticket->id, 'user_id' => $this->requester->id]);
        Notification::fake();

        $ticket = $this->workflow()->hold($ticket, $this->tech, HoldReasonEnum::AwaitingParts);
        $ticket = $this->workflow()->resume($ticket, $this->tech);
        $this->workflow()->resolve($ticket, $this->tech);

        $this->assertNotNotified($this->requester, TeamTicketEventEnum::Held);
        $this->assertNotified($this->requester, TeamTicketEventEnum::Resolved, ['database', 'mail']);
    }

    public function test_bell_preference_silences_info_but_not_actions(): void
    {
        UserNotificationPreference::create([
            'user_id' => $this->requester->id,
            'notification_type' => 'ticket_participant',
            'mail_enabled' => false,
            'database_enabled' => false,
        ]);

        $this->workflow()->resolve($this->started(), $this->tech);

        $this->assertNotNotified($this->requester, TeamTicketEventEnum::Started);
        $this->assertNotified($this->requester, TeamTicketEventEnum::Resolved, ['database', 'mail']);
    }

    public function test_a_rolled_back_step_notifies_nobody(): void
    {
        $ticket = $this->open();
        Notification::fake();

        try {
            DB::transaction(function () use ($ticket): void {
                $this->workflow()->assign($ticket, $this->tech->employee, $this->supervisor);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        Notification::assertNothingSent();
    }

    // ── SLA ─────────────────────────────────────────────────────────────

    public function test_sla_warning_breach_and_escalation_each_go_out_once(): void
    {
        Permission::findOrCreate(TeamTicketNotifier::PERMISSION_ESCALATION, 'web');
        [, $manager] = $this->person();
        $manager->givePermissionTo(TeamTicketNotifier::PERMISSION_ESCALATION);

        $this->started();
        $run = fn () => app()->call([new ProcessTeamTicketSla, 'handle']);

        $this->travel(100)->minutes(); // 83 %
        $run();
        $run();
        $this->assertNotified($this->tech, TeamTicketEventEnum::SlaWarning, ['database', 'mail']);
        Notification::assertSentToTimes($this->tech, TeamTicketNotification::class, 2); // assigned + warning

        $this->travel(30)->minutes(); // breached
        $run();
        $run();
        $this->assertNotified($this->tech, TeamTicketEventEnum::SlaBreached, ['database', 'mail']);
        $this->assertNotified($this->supervisor, TeamTicketEventEnum::SlaBreached, ['database', 'mail']);
        $this->assertNotNotified($manager, TeamTicketEventEnum::SlaEscalated);

        $this->travel(4)->hours();
        $run();
        $run();
        Notification::assertSentToTimes($manager, TeamTicketNotification::class, 1);
        $this->assertNotified($manager, TeamTicketEventEnum::SlaEscalated, ['database', 'mail']);
    }

    public function test_unassigned_ticket_warns_the_supervisor(): void
    {
        $this->open();
        Notification::fake();

        $this->travel(100)->minutes();
        app()->call([new ProcessTeamTicketSla, 'handle']);

        $this->assertNotified($this->supervisor, TeamTicketEventEnum::SlaWarning, ['database', 'mail']);
    }

    public function test_mail_renders_with_the_request_link(): void
    {
        $ticket = $this->assigned();
        $mail = (new TeamTicketNotification($ticket, TeamTicketEventEnum::Assigned, ['mail'], 'Ayşe Amir'))
            ->toMail($this->tech)
            ->render();

        $this->assertStringContainsString($ticket->ticket_no, (string) $mail);
        $this->assertStringContainsString('/requests/'.$ticket->id, (string) $mail);
        $this->assertStringContainsString($this->team->name, (string) $mail);
    }
}
