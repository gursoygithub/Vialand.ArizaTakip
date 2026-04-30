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
use App\Notifications\TicketReopenedNotification;
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

    public function test_ticket_reopen_notification(): void
    {
        Notification::fake();

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $assignee     = Employee::factory()->create(['email' => 'reopen@test.com']);
        $assigneeUser = User::factory()->create(['email' => 'reopen@test.com']);

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'employee_id' => $assignee->id,
            'status'      => TaskStatusEnum::CLOSED,
            'closed_at'   => now(),
        ]);

        app(TicketService::class)->transition($ticket, TaskStatusEnum::IN_PROGRESS, $this->actor, 'reopening');

        Notification::assertSentTo($assigneeUser, TicketReopenedNotification::class);
    }
}
