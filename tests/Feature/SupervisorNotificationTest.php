<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Mail\TicketOnHoldMail;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketReassignedNotification;
use App\Notifications\TicketResolvedNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class SupervisorNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed permissions so Filament gates work in Livewire tests.
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        // Placeholder auth for boot hooks (created_by etc.).
        $this->actingAs(User::factory()->create(['username' => 'setup']));
    }

    /**
     * Create an Employee paired with a User on matching email.
     * Employee::user() requires: same email, username NOT NULL, status ACTIVE.
     *
     * @return array{0: Employee, 1: User}
     */
    private function makeEmployeeWithUser(array $userExtras = []): array
    {
        $email    = fake()->unique()->safeEmail();
        $user     = User::factory()->create(array_merge([
            'email'    => $email,
            'username' => 'u_' . fake()->unique()->numerify('######'),
            'status'   => UserStatusEnum::ACTIVE,
        ], $userExtras));
        $employee = Employee::factory()->create([
            'email'  => $email,
            'status' => ActiveStatusEnum::ACTIVE,
        ]);

        return [$employee, $user];
    }

    /** Build a minimal ticket with area/sub/unit so Filament routes resolve. */
    private function makeTicket(array $overrides = []): Ticket
    {
        $area    = \App\Models\Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea = \App\Models\SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = \App\Models\Unit::factory()->create();

        return Ticket::factory()->create(array_merge([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::ASSIGNED,
        ], $overrides));
    }

    // ── CHANGE 1 ─────────────────────────────────────────────────────────────

    /**
     * Submitting the ON_HOLD action without a note must fail validation.
     * The form field carries ->required() only for ON_HOLD.
     */
    public function test_on_hold_action_requires_a_note(): void
    {
        [$assigneeEmployee, $assigneeUser] = $this->makeEmployeeWithUser();
        $assigneeUser->givePermissionTo(['view_any_ticket', 'view_ticket', 'ticket.view.all']);
        $this->actingAs($assigneeUser);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $assigneeEmployee->id,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->callAction('to_' . TaskStatusEnum::ON_HOLD->value, data: ['note' => ''])
            ->assertHasActionErrors(['note' => 'required']);
    }

    /**
     * Regression: other transitions (e.g. IN_PROGRESS) still accept an empty note.
     */
    public function test_in_progress_action_does_not_require_a_note(): void
    {
        [$assigneeEmployee, $assigneeUser] = $this->makeEmployeeWithUser();
        $assigneeUser->givePermissionTo(['view_any_ticket', 'view_ticket', 'ticket.view.all']);
        $this->actingAs($assigneeUser);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $assigneeEmployee->id,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->callAction('to_' . TaskStatusEnum::IN_PROGRESS->value, data: ['note' => ''])
            ->assertHasNoActionErrors();
    }

    // ── CHANGE 2 + 3 — ON_HOLD supervisor notification ───────────────────────

    /**
     * When a ticket is put ON_HOLD and the supervisor is NOT a participant,
     * they receive a DB notification + mail.
     */
    public function test_on_hold_notifies_group_supervisor_when_not_participant(): void
    {
        Notification::fake();
        Mail::fake();

        [$assigneeEmployee, $assigneeUser] = $this->makeEmployeeWithUser();
        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $creator = User::factory()->create();
        $group   = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $assigneeEmployee->id,
            'group_id'    => $group->id,
            'created_by'  => $creator->id,
        ]);

        app(TicketService::class)->transition(
            $ticket,
            TaskStatusEnum::ON_HOLD,
            $assigneeUser,
            'Parça bekleniyor.',
        );

        Notification::assertSentTo($supervisorUser, TicketStatusChangedNotification::class);
        Mail::assertQueued(TicketOnHoldMail::class, fn ($mail) =>
            $mail->hasTo($supervisorUser->email)
        );
    }

    /**
     * The standard participants (creator, assignee) still get the ON_HOLD
     * notification via notifyParticipants — regression guard.
     */
    public function test_on_hold_still_notifies_existing_participants(): void
    {
        Notification::fake();
        Mail::fake();

        [$assigneeEmployee, $assigneeUser] = $this->makeEmployeeWithUser();
        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $creator = User::factory()->create(['username' => 'creator_' . uniqid()]);
        $group   = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $assigneeEmployee->id,
            'group_id'    => $group->id,
            'created_by'  => $creator->id,
        ]);

        app(TicketService::class)->transition(
            $ticket,
            TaskStatusEnum::ON_HOLD,
            $assigneeUser,
            'Parça bekleniyor.',
        );

        // Creator is a participant → gets the standard notifyParticipants bell.
        Notification::assertSentTo($creator, TicketStatusChangedNotification::class);
    }

    /**
     * When the supervisor IS already a participant (e.g. they created the
     * ticket), they must NOT receive a second notification.
     */
    public function test_on_hold_does_not_double_notify_supervisor_who_is_participant(): void
    {
        Notification::fake();
        Mail::fake();

        [$assigneeEmployee, $assigneeUser] = $this->makeEmployeeWithUser();
        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $group = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);

        // Supervisor IS the creator → already a participant.
        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $assigneeEmployee->id,
            'group_id'    => $group->id,
            'created_by'  => $supervisorUser->id,
        ]);

        app(TicketService::class)->transition(
            $ticket,
            TaskStatusEnum::ON_HOLD,
            $assigneeUser,
            'Parça bekleniyor.',
        );

        // Supervisor gets the notifyParticipants bell (as creator), but NOT
        // the extra supervisor-specific notification.
        $sent = Notification::sent($supervisorUser, TicketStatusChangedNotification::class);
        $this->assertCount(1, $sent);

        // No mail should be queued to the supervisor.
        Mail::assertNotQueued(TicketOnHoldMail::class, fn ($mail) =>
            $mail->hasTo($supervisorUser->email)
        );
    }

    /**
     * Supervisor IS the actor — must not self-notify.
     */
    public function test_on_hold_does_not_self_notify_supervisor_who_is_actor(): void
    {
        Notification::fake();
        Mail::fake();

        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $group = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);

        // Supervisor is also the assignee so they can trigger the ON_HOLD action.
        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $supervisorEmployee->id,
            'group_id'    => $group->id,
        ]);

        app(TicketService::class)->transition(
            $ticket,
            TaskStatusEnum::ON_HOLD,
            $supervisorUser,
            'Kendim beklemeye aldım.',
        );

        Notification::assertNotSentTo($supervisorUser, TicketStatusChangedNotification::class);
        Mail::assertNotQueued(TicketOnHoldMail::class, fn ($mail) =>
            $mail->hasTo($supervisorUser->email)
        );
    }

    // ── CHANGE 4 — Reassign supervisor notification ──────────────────────────

    /**
     * When a ticket is reassigned, the group supervisor receives
     * TicketReassignedNotification (which includes mail via its toMail()).
     */
    public function test_reassign_notifies_group_supervisor(): void
    {
        Notification::fake();
        Mail::fake();

        [$oldEmployee, $oldUser]           = $this->makeEmployeeWithUser();
        [$newEmployee]                      = $this->makeEmployeeWithUser();
        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $creator = User::factory()->create(['username' => 'creator_' . uniqid()]);
        $group   = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);
        $actor   = User::factory()->create(['username' => 'actor_' . uniqid()]);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $oldEmployee->id,
            'group_id'    => $group->id,
            'created_by'  => $creator->id,
        ]);

        app(TicketService::class)->reassign($ticket, $newEmployee->id, $actor);

        Notification::assertSentTo($supervisorUser, TicketReassignedNotification::class);
    }

    /**
     * When supervisor IS the creator, they only get one notification
     * (TicketReassignedNotification via notifyCreatorOfReassignment).
     * The supervisor block must skip them to avoid a duplicate.
     */
    public function test_reassign_does_not_double_notify_supervisor_who_is_creator(): void
    {
        Notification::fake();
        Mail::fake();

        [$oldEmployee]                      = $this->makeEmployeeWithUser();
        [$newEmployee]                      = $this->makeEmployeeWithUser();
        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $group  = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);
        $actor  = User::factory()->create(['username' => 'actor_' . uniqid()]);

        // Supervisor IS the creator.
        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $oldEmployee->id,
            'group_id'    => $group->id,
            'created_by'  => $supervisorUser->id,
        ]);

        app(TicketService::class)->reassign($ticket, $newEmployee->id, $actor);

        // Exactly one TicketReassignedNotification (from notifyCreatorOfReassignment).
        $this->assertCount(
            1,
            Notification::sent($supervisorUser, TicketReassignedNotification::class),
        );
    }

    /**
     * When supervisor IS the new assignee, they get TicketAssignedNotification
     * via notifyAssignee — must NOT also receive TicketReassignedNotification
     * from the supervisor block.
     */
    public function test_reassign_does_not_notify_supervisor_who_is_new_assignee(): void
    {
        Notification::fake();
        Mail::fake();

        [$oldEmployee]                      = $this->makeEmployeeWithUser();
        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $creator = User::factory()->create(['username' => 'creator_' . uniqid()]);
        $group   = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);
        $actor   = User::factory()->create(['username' => 'actor_' . uniqid()]);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $oldEmployee->id,
            'group_id'    => $group->id,
            'created_by'  => $creator->id,
        ]);

        // Supervisor IS the new assignee.
        app(TicketService::class)->reassign($ticket, $supervisorEmployee->id, $actor);

        Notification::assertNotSentTo($supervisorUser, TicketReassignedNotification::class);
    }

    // ── CHANGE 5 — Resolved supervisor notification ──────────────────────────

    /**
     * When a ticket is resolved, the group supervisor receives
     * TicketResolvedNotification (mail + DB via the notification's own via()).
     */
    public function test_resolved_notifies_group_supervisor(): void
    {
        Notification::fake();
        Mail::fake();

        [$assigneeEmployee, $assigneeUser] = $this->makeEmployeeWithUser();
        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $creator = User::factory()->create(['username' => 'creator_' . uniqid()]);
        $group   = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::IN_PROGRESS,
            'employee_id' => $assigneeEmployee->id,
            'group_id'    => $group->id,
            'created_by'  => $creator->id,
        ]);

        app(TicketService::class)->transition(
            $ticket,
            TaskStatusEnum::RESOLVED,
            $assigneeUser,
        );

        Notification::assertSentTo($supervisorUser, TicketResolvedNotification::class);
    }

    /**
     * When supervisor IS the creator, only one TicketResolvedNotification
     * is sent — the resolved block must skip the supervisor to avoid duplicate.
     */
    public function test_resolved_does_not_double_notify_supervisor_who_is_creator(): void
    {
        Notification::fake();
        Mail::fake();

        [$assigneeEmployee, $assigneeUser] = $this->makeEmployeeWithUser();
        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $group = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);

        // Supervisor IS the creator.
        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::IN_PROGRESS,
            'employee_id' => $assigneeEmployee->id,
            'group_id'    => $group->id,
            'created_by'  => $supervisorUser->id,
        ]);

        app(TicketService::class)->transition(
            $ticket,
            TaskStatusEnum::RESOLVED,
            $assigneeUser,
        );

        $this->assertCount(
            1,
            Notification::sent($supervisorUser, TicketResolvedNotification::class),
        );
    }

    /**
     * When supervisor IS the actor (resolver), they must not self-notify.
     */
    public function test_resolved_does_not_self_notify_supervisor_who_is_actor(): void
    {
        Notification::fake();
        Mail::fake();

        [$supervisorEmployee, $supervisorUser] = $this->makeEmployeeWithUser();
        $creator = User::factory()->create(['username' => 'creator_' . uniqid()]);
        $group   = Group::factory()->create(['employee_id' => $supervisorEmployee->id]);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::IN_PROGRESS,
            'employee_id' => $supervisorEmployee->id,
            'group_id'    => $group->id,
            'created_by'  => $creator->id,
        ]);

        // Supervisor IS the actor who resolves.
        app(TicketService::class)->transition(
            $ticket,
            TaskStatusEnum::RESOLVED,
            $supervisorUser,
        );

        Notification::assertNotSentTo($supervisorUser, TicketResolvedNotification::class);
    }
}
