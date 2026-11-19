<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TeamTicketEventEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\TeamResource\Pages\EditTeam;
use App\Jobs\ProcessTeamTicketSla;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketHold;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\TeamTicketNotification;
use App\Services\FcmService;
use App\Services\TeamPerformanceService;
use App\Services\TeamTicketNotifier;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\GrantsShieldPermissions;
use Tests\TestCase;

/**
 * Holds pause the SLA, so they are recorded per person, the supervisor
 * hears about them (switchable on "Bildirim Tercihlerim") and is alerted
 * once when a hold outlasts the team's limit. The team runs 7/24.
 */
class HoldControlsTest extends TestCase
{
    use GrantsShieldPermissions, RefreshDatabase;

    private TicketWorkflowService $workflow;

    private Team $team;

    private User $requester;

    private User $supervisor;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        Notification::fake();
        $this->seed(PermissionSeeder::class);
        $this->mock(FcmService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->andReturnNull();
            $mock->shouldReceive('sendToUsers')->andReturnNull();
        });

        $this->requester = $this->person()[1];
        [$supervisorEmployee, $this->supervisor] = $this->person();
        [$techEmployee, $this->tech] = $this->person();
        $this->actingAs($this->requester);

        $this->team = Team::factory()->create(['supervisor_employee_id' => $supervisorEmployee->id, 'hold_alert_minutes' => 480]);
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

        return [Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE]), $user];
    }

    private function held(HoldReasonEnum $reason = HoldReasonEnum::AwaitingParts): Ticket
    {
        $ticket = $this->workflow->open($this->requester, $this->team, TaskPriorityEnum::Medium, 'Klima su akıtıyor.');
        $ticket = $this->workflow->startWork($this->workflow->assign($ticket, $this->tech->employee, $this->supervisor), $this->tech);

        return $this->workflow->hold($ticket, $this->tech, $reason, 'Kompresör siparişte.');
    }

    private function sweep(): void
    {
        app()->call([new ProcessTeamTicketSla, 'handle']);
    }

    private function preference(User $user, string $type, bool $database, bool $mail): void
    {
        UserNotificationPreference::create(['user_id' => $user->id, 'notification_type' => $type, 'database_enabled' => $database, 'mail_enabled' => $mail]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function channelsSent(User $user, TeamTicketEventEnum $event): array
    {
        return Notification::sent($user, TeamTicketNotification::class)
            ->filter(fn (TeamTicketNotification $n): bool => $n->event === $event)
            ->map(fn (TeamTicketNotification $n): array => $n->channels)
            ->values()
            ->all();
    }

    // ── Recording ───────────────────────────────────────────────────────

    public function test_each_hold_is_recorded_with_its_working_time(): void
    {
        $ticket = $this->held();

        $hold = TicketHold::sole();
        $this->assertSame($this->tech->employee->id, $hold->employee_id);
        $this->assertSame(HoldReasonEnum::AwaitingParts, $hold->reason);
        $this->assertNull($hold->ended_at);

        $this->travel(90)->minutes();
        $this->workflow->resume($ticket, $this->tech);

        $this->assertSame(90, $hold->fresh()->working_minutes);
        $this->assertNotNull($hold->fresh()->ended_at);
    }

    public function test_cancelling_a_held_request_ends_the_hold(): void
    {
        $ticket = $this->held();
        $this->travel(30)->minutes();

        $this->workflow->cancel($ticket, $this->supervisor, 'Gerek kalmadı.');

        $this->assertSame(30, TicketHold::sole()->working_minutes);
    }

    // ── Supervisor notices and their switches ───────────────────────────

    public function test_supervisor_gets_bell_and_mail_by_default(): void
    {
        $this->held();

        $this->assertSame([['database', 'mail']], $this->channelsSent($this->supervisor, TeamTicketEventEnum::Held));
    }

    public function test_supervisor_can_switch_off_the_hold_mail_or_both(): void
    {
        $this->preference($this->supervisor, TeamTicketNotifier::PREFERENCE_TEAM_HOLD, true, false);
        $this->held();
        $this->assertSame([['database']], $this->channelsSent($this->supervisor, TeamTicketEventEnum::Held));

        Notification::fake();
        UserNotificationPreference::query()->update(['database_enabled' => false]);
        $this->held();
        $this->assertSame([], $this->channelsSent($this->supervisor, TeamTicketEventEnum::Held));
    }

    public function test_hold_switch_does_not_silence_the_requester(): void
    {
        $this->preference($this->supervisor, TeamTicketNotifier::PREFERENCE_TEAM_HOLD, false, false);

        $this->held(HoldReasonEnum::AwaitingRequester);

        $this->assertSame([['database', 'mail']], $this->channelsSent($this->requester, TeamTicketEventEnum::Held));
    }

    // ── Overdue alert ───────────────────────────────────────────────────

    public function test_supervisor_is_alerted_once_when_a_hold_outlasts_the_limit(): void
    {
        $this->held();

        $this->travel(479)->minutes();
        $this->sweep();
        $this->assertSame([], $this->channelsSent($this->supervisor, TeamTicketEventEnum::HoldOverdue));

        $this->travel(2)->minutes();
        $this->sweep();
        $this->sweep();
        $this->assertSame([['database', 'mail']], $this->channelsSent($this->supervisor, TeamTicketEventEnum::HoldOverdue));
        $this->assertNotNull(TicketHold::sole()->alerted_at);
    }

    public function test_no_alert_when_the_team_limit_is_off_or_the_hold_ended(): void
    {
        $this->team->update(['hold_alert_minutes' => 0]);
        $first = $this->held();
        $this->travel(2)->days();
        $this->sweep();
        $this->assertSame([], $this->channelsSent($this->supervisor, TeamTicketEventEnum::HoldOverdue));
        $this->workflow->cancel($first, $this->supervisor, 'Gerek kalmadı.');

        $this->team->update(['hold_alert_minutes' => 60]);
        $ticket = $this->held();
        $this->workflow->resume($ticket, $this->tech);
        $this->travel(2)->hours();
        $this->sweep();
        $this->assertSame([], $this->channelsSent($this->supervisor, TeamTicketEventEnum::HoldOverdue));
    }

    public function test_overdue_alert_follows_its_own_switch(): void
    {
        $this->preference($this->supervisor, TeamTicketNotifier::PREFERENCE_TEAM_HOLD_OVERDUE, true, false);
        $this->held();

        $this->travel(9)->hours();
        $this->sweep();

        $this->assertSame([['database']], $this->channelsSent($this->supervisor, TeamTicketEventEnum::HoldOverdue));
    }

    // ── Screens ─────────────────────────────────────────────────────────

    public function test_preferences_page_shows_only_switches_that_work_in_the_team_model(): void
    {
        $this->actingAs($this->supervisor);
        $this->assertSame(['ticket_participant', 'team_hold', 'team_hold_overdue'], array_keys(NotificationPreferences::rows()));

        Livewire::test(NotificationPreferences::class)
            ->assertSee(__('ui.pref_team_hold'))
            ->assertDontSee('SLA Uyarısı')
            ->set('data.team_hold_mail', false)
            ->assertNotified();

        $this->assertTrue($this->supervisor->wantsNotification('team_hold', 'database'));
        $this->assertFalse($this->supervisor->wantsNotification('team_hold', 'mail'));

        $this->actingAs($this->tech);
        $this->assertSame(['ticket_participant'], array_keys(NotificationPreferences::rows()));
    }

    public function test_team_limit_is_entered_in_hours(): void
    {
        $this->actingAs($this->userWithResourcePermissions(['team']));

        Livewire::test(EditTeam::class, ['record' => $this->team->getKey()])
            ->assertFormSet(['hold_alert_minutes' => 8])
            ->fillForm(['hold_alert_minutes' => 1.5])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(90, $this->team->fresh()->hold_alert_minutes);
    }

    public function test_person_report_shows_holds(): void
    {
        $ticket = $this->held();
        $this->travel(120)->minutes();
        $this->workflow->resume($ticket, $this->tech);
        $this->held(HoldReasonEnum::AwaitingVendor);
        $this->travel(30)->minutes();

        $holds = app(TeamPerformanceService::class)->holdFigures($this->tech->employee);

        $this->assertSame(2, $holds['count']);
        $this->assertSame(1, $holds['open']);
        $this->assertSame(150, $holds['minutes']);
        $this->assertSame([HoldReasonEnum::AwaitingParts->getLabel() => 1, HoldReasonEnum::AwaitingVendor->getLabel() => 1], $holds['reasons']);

        $viewer = $this->person()[1];
        foreach (['view_any_employee', 'view_employee', 'view_all_employees'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $viewer->givePermissionTo(['view_any_employee', 'view_employee', 'view_all_employees']);
        $this->actingAs($viewer);

        Livewire::test(ViewEmployee::class, ['record' => $this->tech->employee->getKey()])
            ->assertSee(__('ui.employee_holds_value', ['count' => 2, 'time' => '2 sa 30 dk']));
    }
}
