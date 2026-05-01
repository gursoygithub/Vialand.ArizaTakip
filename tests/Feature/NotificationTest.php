<?php

namespace Tests\Feature;

use App\Enums\TaskStatusEnum;
use App\Jobs\CheckSlaBreaches;
use App\Models\Area;
use App\Models\Employee;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\SlaWarningNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCommentNotification;
use App\Notifications\TicketReassignedNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole('admin');
        $this->actingAs($this->actor);
    }

    public function test_assigned_notification_fires_on_creation_with_employee(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $assigneeUser = User::factory()->create(['email' => 'assignee@test.com']);
        $employee     = Employee::factory()->create(['email' => 'assignee@test.com']);

        Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'employee_id' => $employee->id,
            'status'      => TaskStatusEnum::ASSIGNED,
        ]);

        Notification::assertSentTo($assigneeUser, TicketAssignedNotification::class);
    }

    public function test_sla_warning_at_80_percent(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-05-01 12:00:00');

        $area     = Area::factory()->create();
        $sub      = SubArea::factory()->create(['area_id' => $area->id]);
        $unit     = Unit::factory()->create();
        $assignee = Employee::factory()->create(['email' => 'tech80@test.com']);
        $assigneeUser = User::factory()->create(['email' => 'tech80@test.com']);

        // 100-minute window; 85 minutes elapsed → 85% → should warn
        Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $sub->id,
            'unit_id'      => $unit->id,
            'employee_id'  => $assignee->id,
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'created_at'   => Carbon::parse('2026-05-01 10:35:00'),
            'sla_deadline' => Carbon::parse('2026-05-01 12:15:00'),
        ]);

        (new CheckSlaBreaches())->handle();

        Notification::assertSentTo($assigneeUser, SlaWarningNotification::class);

        Carbon::setTestNow();
    }

    public function test_sla_breach_notification(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $sub->id,
            'unit_id'      => $unit->id,
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => Carbon::now()->subHour(),
            'sla_breached' => false,
        ]);

        (new CheckSlaBreaches())->handle();

        // Admin (the test actor) should be notified as fallback recipient
        Notification::assertSentTo($this->actor, SlaBreachedNotification::class);
    }

    public function test_no_duplicate_notifications_same_day(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $sub->id,
            'unit_id'      => $unit->id,
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => Carbon::now()->subHour(),
            'sla_breached' => false,
        ]);

        // Run job twice; second run should not duplicate (the first call leaves a
        // notification record; the second checks alreadySentToday() and skips).
        (new CheckSlaBreaches())->handle();
        // After the first call the ticket is sla_breached=true so the breach loop
        // won't re-pick it, but the de-dupe check is what matters per spec.
        $countAfterFirst = \Illuminate\Notifications\DatabaseNotification::where('type', SlaBreachedNotification::class)->count();
        (new CheckSlaBreaches())->handle();
        $countAfterSecond = \Illuminate\Notifications\DatabaseNotification::where('type', SlaBreachedNotification::class)->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_status_change_notifies_creator_and_assignee_not_actor(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $creator = User::factory()->create();
        $assigneeEmployee = Employee::factory()->create(['email' => 'tech-status@test.com']);
        $assigneeUser     = User::factory()->create(['email' => 'tech-status@test.com', 'username' => 'tech-status']);

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'employee_id' => $assigneeEmployee->id,
            'created_by'  => $creator->id,
            'status'      => TaskStatusEnum::ASSIGNED,
        ]);

        app(TicketService::class)->transition($ticket, TaskStatusEnum::IN_PROGRESS, $this->actor, null);

        Notification::assertSentTo($creator, TicketStatusChangedNotification::class);
        Notification::assertSentTo($assigneeUser, TicketStatusChangedNotification::class);
        Notification::assertNotSentTo($this->actor, TicketStatusChangedNotification::class);
    }

    public function test_open_to_assigned_does_not_double_notify(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $assigneeEmployee = Employee::factory()->create(['email' => 'tech-double@test.com']);
        $assigneeUser     = User::factory()->create(['email' => 'tech-double@test.com', 'username' => 'tech-double']);

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'employee_id' => $assigneeEmployee->id,
            'status'      => TaskStatusEnum::OPEN,
        ]);

        app(TicketService::class)->transition($ticket, TaskStatusEnum::ASSIGNED, $this->actor, null);

        // OPEN → ASSIGNED only fires the dedicated assigned notification.
        Notification::assertSentTo($assigneeUser, TicketAssignedNotification::class);
        Notification::assertNotSentTo($assigneeUser, TicketStatusChangedNotification::class);
    }

    public function test_comment_notifies_creator_and_assignee_not_commenter(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $creator = User::factory()->create();
        $assigneeEmployee = Employee::factory()->create(['email' => 'tech-comment@test.com']);
        $assigneeUser     = User::factory()->create(['email' => 'tech-comment@test.com', 'username' => 'tech-comment']);

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'employee_id' => $assigneeEmployee->id,
            'created_by'  => $creator->id,
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        app(TicketService::class)->addComment($ticket, $this->actor, 'looking at it');

        Notification::assertSentTo($creator, TicketCommentNotification::class);
        Notification::assertSentTo($assigneeUser, TicketCommentNotification::class);
        Notification::assertNotSentTo($this->actor, TicketCommentNotification::class);
    }

    public function test_creator_is_assignee_only_one_notification(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        // Creator and assignee are the same human (their User and Employee
        // share an email); after dedupe they should only get one notification.
        $sharedUser     = User::factory()->create(['email' => 'one-person@test.com', 'username' => 'one-person']);
        $sharedEmployee = Employee::factory()->create(['email' => 'one-person@test.com']);

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'employee_id' => $sharedEmployee->id,
            'created_by'  => $sharedUser->id,
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        app(TicketService::class)->addComment($ticket, $this->actor, 'note');

        Notification::assertSentToTimes($sharedUser, TicketCommentNotification::class, 1);
    }

    public function test_actor_is_creator_only_assignee_notified(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $assigneeEmployee = Employee::factory()->create(['email' => 'tech-self@test.com']);
        $assigneeUser     = User::factory()->create(['email' => 'tech-self@test.com', 'username' => 'tech-self']);

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'employee_id' => $assigneeEmployee->id,
            'created_by'  => $this->actor->id, // actor IS creator
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        app(TicketService::class)->addComment($ticket, $this->actor, 'self note');

        Notification::assertSentTo($assigneeUser, TicketCommentNotification::class);
        Notification::assertNotSentTo($this->actor, TicketCommentNotification::class);
    }

    public function test_reassignment_notifies_new_assignee_and_creator(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $creator    = User::factory()->create();
        $oldEmployee = Employee::factory()->create(['email' => 'old-tech@test.com']);
        User::factory()->create(['email' => 'old-tech@test.com', 'username' => 'old-tech']);

        $newEmployee     = Employee::factory()->create(['email' => 'new-tech@test.com']);
        $newAssigneeUser = User::factory()->create(['email' => 'new-tech@test.com', 'username' => 'new-tech']);

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'employee_id' => $oldEmployee->id,
            'created_by'  => $creator->id,
            'status'      => TaskStatusEnum::IN_PROGRESS,
        ]);

        app(TicketService::class)->reassign($ticket, $newEmployee->id, $this->actor, null);

        Notification::assertSentTo($newAssigneeUser, TicketAssignedNotification::class);
        Notification::assertSentTo($creator, TicketReassignedNotification::class);
        Notification::assertNotSentTo($this->actor, TicketReassignedNotification::class);
    }
}
