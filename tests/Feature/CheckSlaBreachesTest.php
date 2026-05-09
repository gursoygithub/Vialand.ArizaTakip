<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\UserStatusEnum;
use App\Jobs\CheckSlaBreaches;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\SlaWarningNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckSlaBreachesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Boot hooks on Ticket/Company/Group read auth()->id() for created_by.
        $this->actingAs(User::factory()->create(['username' => 'setup_user']));
    }

    /**
     * Create an Employee whose user() relation resolves correctly.
     * Employee::user() matches on email + requires username NOT NULL + status ACTIVE.
     *
     * @return array{0: Employee, 1: User}
     */
    private function makeEmployeeWithUser(): array
    {
        $email    = fake()->unique()->safeEmail();
        $user     = User::factory()->create([
            'email'    => $email,
            'username' => 'u_' . fake()->unique()->numerify('######'),
            'status'   => UserStatusEnum::ACTIVE,
        ]);
        $employee = Employee::factory()->create([
            'email'  => $email,
            'status' => ActiveStatusEnum::ACTIVE,
        ]);

        return [$employee, $user];
    }

    /**
     * Force a ticket into a "past deadline, not yet flagged" state by writing
     * directly to the DB, bypassing TicketObserver::saving which would flip
     * sla_breached = true on save.
     */
    private function setBreahedState(Ticket $ticket, int $minutesAgo = 60): void
    {
        DB::table('tickets')
            ->where('id', $ticket->id)
            ->update([
                'sla_deadline' => now()->subMinutes($minutesAgo),
                'sla_breached' => false,
            ]);
    }

    /**
     * Force a ticket into an "80%+ elapsed, not yet breached" state.
     * total = 100 min window, elapsed = 85 min → 85 % ≥ 80 %.
     */
    private function setWarningState(Ticket $ticket): void
    {
        DB::table('tickets')
            ->where('id', $ticket->id)
            ->update([
                'created_at'   => now()->subMinutes(85),
                'sla_deadline' => now()->addMinutes(15),
                'sla_breached' => false,
            ]);
    }

    // ── Test 1 ──────────────────────────────────────────────────────────────

    public function test_unassigned_ticket_with_group_notifies_supervisor_on_breach(): void
    {
        Notification::fake();

        [$supervisor, $supervisorUser] = $this->makeEmployeeWithUser();
        $creator = User::factory()->create(['username' => 'creator_' . uniqid()]);
        $group   = Group::factory()->create(['employee_id' => $supervisor->id]);

        $ticket = Ticket::factory()->create([
            'employee_id' => null,
            'group_id'    => $group->id,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => $creator->id,
        ]);

        $this->setBreahedState($ticket);

        (new CheckSlaBreaches())->handle();

        Notification::assertSentTo($supervisorUser, SlaBreachedNotification::class);
        Notification::assertSentTo($creator, SlaBreachedNotification::class);
    }

    // ── Test 2 ──────────────────────────────────────────────────────────────

    public function test_unassigned_ticket_with_group_notifies_supervisor_on_warning(): void
    {
        Notification::fake();

        [$supervisor, $supervisorUser] = $this->makeEmployeeWithUser();
        $creator = User::factory()->create(['username' => 'creator_' . uniqid()]);
        $group   = Group::factory()->create(['employee_id' => $supervisor->id]);

        $ticket = Ticket::factory()->create([
            'employee_id' => null,
            'group_id'    => $group->id,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => $creator->id,
        ]);

        $this->setWarningState($ticket);

        (new CheckSlaBreaches())->handle();

        Notification::assertSentTo($supervisorUser, SlaWarningNotification::class);
        Notification::assertSentTo($creator, SlaWarningNotification::class);
    }

    // ── Test 3 ──────────────────────────────────────────────────────────────

    public function test_assigned_ticket_notifies_assignee_and_creator_not_supervisor(): void
    {
        Notification::fake();

        [$assignee, $assigneeUser]     = $this->makeEmployeeWithUser();
        [$supervisor, $supervisorUser] = $this->makeEmployeeWithUser();
        $creator = User::factory()->create(['username' => 'creator_' . uniqid()]);
        $group   = Group::factory()->create(['employee_id' => $supervisor->id]);

        $ticket = Ticket::factory()->create([
            'employee_id' => $assignee->id,
            'group_id'    => $group->id,
            'status'      => TaskStatusEnum::ASSIGNED,
            'created_by'  => $creator->id,
        ]);

        $this->setBreahedState($ticket);

        (new CheckSlaBreaches())->handle();

        Notification::assertSentTo($assigneeUser, SlaBreachedNotification::class);
        Notification::assertSentTo($creator, SlaBreachedNotification::class);
        Notification::assertNotSentTo($supervisorUser, SlaBreachedNotification::class);
    }

    // ── Test 4 ──────────────────────────────────────────────────────────────

    public function test_unassigned_ticket_with_no_group_notifies_only_creator(): void
    {
        Notification::fake();

        $creator = User::factory()->create(['username' => 'creator_' . uniqid()]);

        $ticket = Ticket::factory()->create([
            'employee_id' => null,
            'group_id'    => null,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => $creator->id,
        ]);

        $this->setBreahedState($ticket);

        (new CheckSlaBreaches())->handle();

        Notification::assertSentTo($creator, SlaBreachedNotification::class);
        // Only creator — no supervisor to add.
        $this->assertCount(
            1,
            Notification::sent($creator, SlaBreachedNotification::class),
        );
    }

    // ── Test 5 ──────────────────────────────────────────────────────────────

    public function test_supervisor_is_creator_sends_only_one_notification(): void
    {
        Notification::fake();

        [$supervisor, $supervisorUser] = $this->makeEmployeeWithUser();
        $group = Group::factory()->create(['employee_id' => $supervisor->id]);

        // supervisorUser is also the ticket creator.
        $ticket = Ticket::factory()->create([
            'employee_id' => null,
            'group_id'    => $group->id,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => $supervisorUser->id,
        ]);

        $this->setBreahedState($ticket);

        (new CheckSlaBreaches())->handle();

        // The unique('id') dedup must collapse supervisor + creator into one.
        Notification::assertSentTo($supervisorUser, SlaBreachedNotification::class);
        $this->assertCount(
            1,
            Notification::sent($supervisorUser, SlaBreachedNotification::class),
        );
    }
}
