<?php

namespace Tests\Feature;

use App\Enums\TaskStatusEnum;
use App\Models\Area;
use App\Models\Employee;
use App\Models\Group;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketCreationNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;
    private SubArea $subArea;
    private Unit $unit;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        // Must be authenticated before any factory with `created_by` boot hooks
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);

        $this->area    = Area::factory()->create();
        $this->subArea = SubArea::factory()->create(['area_id' => $this->area->id]);
        $this->unit    = Unit::factory()->create();
    }

    public function test_creating_with_employee_notifies_assignee(): void
    {
        Notification::fake();

        $empEmail = 'assignee@test.com';
        $emp      = Employee::factory()->create(['email' => $empEmail]);
        User::factory()->create(['email' => $empEmail, 'username' => 'assignee-user']);

        Ticket::factory()->create([
            'area_id'     => $this->area->id,
            'sub_area_id' => $this->subArea->id,
            'unit_id'     => $this->unit->id,
            'employee_id' => $emp->id,
            'status'      => null,
        ]);

        $assigneeUser = User::where('email', $empEmail)->firstOrFail();
        Notification::assertSentTo($assigneeUser, TicketAssignedNotification::class);
    }

    public function test_creating_with_group_but_no_employee_notifies_supervisor(): void
    {
        Notification::fake();

        $supEmail = 'sup@test.com';
        $supEmp   = Employee::factory()->create(['email' => $supEmail]);
        $supUser  = User::factory()->create(['email' => $supEmail, 'username' => 'sup-user']);

        $group = Group::factory()->create([
            'area_id'     => $this->area->id,
            'employee_id' => $supEmp->id,
        ]);

        Ticket::factory()->create([
            'area_id'     => $this->area->id,
            'sub_area_id' => $this->subArea->id,
            'unit_id'     => $this->unit->id,
            'group_id'    => $group->id,
            'employee_id' => null,
            'status'      => TaskStatusEnum::OPEN,
        ]);

        Notification::assertSentTo($supUser, TicketAssignedNotification::class);
    }

    public function test_creating_with_group_does_not_notify_supervisor_who_is_the_creator(): void
    {
        Notification::fake();

        // Actor is also the group supervisor — should not receive a self-notification
        $actorEmp = Employee::factory()->create(['email' => $this->actor->email]);
        $group    = Group::factory()->create([
            'area_id'     => $this->area->id,
            'employee_id' => $actorEmp->id,
        ]);

        Ticket::factory()->create([
            'area_id'     => $this->area->id,
            'sub_area_id' => $this->subArea->id,
            'unit_id'     => $this->unit->id,
            'group_id'    => $group->id,
            'employee_id' => null,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => $this->actor->id,
        ]);

        Notification::assertNotSentTo($this->actor, TicketAssignedNotification::class);
    }

    public function test_creating_without_employee_or_group_sends_no_notification(): void
    {
        Notification::fake();

        Ticket::factory()->create([
            'area_id'     => $this->area->id,
            'sub_area_id' => $this->subArea->id,
            'unit_id'     => $this->unit->id,
            'employee_id' => null,
            'group_id'    => null,
            'status'      => TaskStatusEnum::OPEN,
        ]);

        Notification::assertNothingSent();
    }
}
