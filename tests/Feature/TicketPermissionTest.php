<?php

namespace Tests\Feature;

use App\Enums\TaskStatusEnum;
use App\Models\Area;
use App\Models\Employee;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Policies\TicketPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        // Authenticate so Model::booted() hooks that set created_by from auth() work
        $this->actingAs(User::factory()->create());
    }

    public function test_default_role_has_zero_permissions(): void
    {
        $role = Role::where('name', 'default')->first();

        $this->assertNotNull($role);
        $this->assertEquals(0, $role->permissions()->count());
    }

    public function test_technician_cannot_view_another_technicians_ticket(): void
    {
        $area    = Area::factory()->create(['name' => 'X', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $technicianA = User::factory()->create(['email' => 'tech_a@test.com']);
        $technicianA->assignRole('technician');

        $technicianB = User::factory()->create(['email' => 'tech_b@test.com']);
        $technicianB->assignRole('technician');

        // Ticket created by technicianB
        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'created_by'  => $technicianB->id,
            'employee_id' => null,
        ]);

        // Row-level visibility lives in Ticket::scopeVisibleBy (driven by
        // ticket.view.own/group/all). The Shield policy gates the action
        // (view_ticket) — the scope filters the rows out for a `view.own`
        // user who is neither creator nor assignee.
        $visibleIds = Ticket::query()->visibleBy($technicianA)->pluck('id')->all();
        $this->assertNotContains($ticket->id, $visibleIds);
    }

    public function test_supervisor_can_view_their_regions_tickets(): void
    {
        $area    = Area::factory()->create(['name' => 'Y', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $supervisor = User::factory()->create(['email' => 'supervisor@test.com']);
        $supervisor->assignRole('supervisor');
        $supervisor->givePermissionTo('view_ticket');

        $employee = Employee::factory()->create(['email' => 'supervisor@test.com']);

        $group = Group::factory()->create([
            'area_id'     => $area->id,
            'employee_id' => $employee->id,
        ]);

        GroupMember::factory()->create([
            'group_id'    => $group->id,
            'employee_id' => $employee->id,
        ]);

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'created_by'  => 1,
        ]);

        $policy = new TicketPolicy();

        $this->assertTrue($policy->view($supervisor, $ticket));
    }

    public function test_default_role_cannot_create_tickets(): void
    {
        $user = User::factory()->create();
        $user->assignRole('default');

        $policy = new TicketPolicy();

        $this->assertFalse($policy->create($user));
    }

    public function test_technician_can_create_tickets(): void
    {
        $user = User::factory()->create();
        $user->assignRole('technician');
        $user->givePermissionTo('create_ticket');

        $policy = new TicketPolicy();

        $this->assertTrue($policy->create($user));
    }

    public function test_only_supervisor_and_admin_can_close_ticket(): void
    {
        $area    = Area::factory()->create(['name' => 'Z', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'created_by'  => 1,
        ]);

        $technician = User::factory()->create();
        $technician->assignRole('technician');

        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->givePermissionTo('ticket.close');

        $policy = new TicketPolicy();

        $this->assertFalse($policy->close($technician, $ticket));
        $this->assertTrue($policy->close($supervisor, $ticket));
    }

    public function test_terminal_state_blocks_delete_including_super_admin(): void
    {
        $area    = Area::factory()->create(['name' => 'T-DEL', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $creator = User::factory()->create();
        $creator->assignRole('technician');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $policy = new TicketPolicy();

        foreach ([TaskStatusEnum::RESOLVED, TaskStatusEnum::CLOSED, TaskStatusEnum::CANCELLED] as $status) {
            $ticket = Ticket::factory()->create([
                'area_id'     => $area->id,
                'sub_area_id' => $subArea->id,
                'unit_id'     => $unit->id,
                'created_by'  => $creator->id,
                'status'      => $status,
            ]);

            $this->assertFalse(
                $policy->delete($creator, $ticket),
                "Creator should not be able to delete a {$status->value} ticket",
            );
            $this->assertFalse(
                $policy->delete($superAdmin, $ticket),
                "super_admin should not be able to delete a {$status->value} ticket",
            );
        }
    }

    public function test_non_terminal_delete_allowed_for_creator_and_super_admin(): void
    {
        $area    = Area::factory()->create(['name' => 'NT-DEL', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $creator = User::factory()->create();
        $creator->assignRole('technician');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $stranger = User::factory()->create();
        $stranger->assignRole('technician');

        $policy = new TicketPolicy();

        foreach ([
            TaskStatusEnum::OPEN,
            TaskStatusEnum::ASSIGNED,
            TaskStatusEnum::IN_PROGRESS,
            TaskStatusEnum::ON_HOLD,
        ] as $status) {
            $ticket = Ticket::factory()->create([
                'area_id'     => $area->id,
                'sub_area_id' => $subArea->id,
                'unit_id'     => $unit->id,
                'created_by'  => $creator->id,
                'status'      => $status,
            ]);

            $this->assertTrue(
                $policy->delete($creator, $ticket),
                "Creator should be able to delete their own {$status->value} ticket",
            );
            $this->assertTrue(
                $policy->delete($superAdmin, $ticket),
                "super_admin should be able to delete a {$status->value} ticket",
            );
            $this->assertFalse(
                $policy->delete($stranger, $ticket),
                "A stranger should not be able to delete someone else's {$status->value} ticket",
            );
        }
    }

    public function test_reopen_allowed_for_creator_and_super_admin_on_terminal_tickets(): void
    {
        $area    = Area::factory()->create(['name' => 'REO-OK', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $creator = User::factory()->create();
        $creator->assignRole('technician');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $stranger = User::factory()->create();
        $stranger->assignRole('technician');

        $policy = new TicketPolicy();

        foreach ([TaskStatusEnum::RESOLVED, TaskStatusEnum::CLOSED] as $status) {
            $ticket = Ticket::factory()->create([
                'area_id'     => $area->id,
                'sub_area_id' => $subArea->id,
                'unit_id'     => $unit->id,
                'created_by'  => $creator->id,
                'status'      => $status,
            ]);

            $this->assertTrue(
                $policy->reopen($creator, $ticket),
                "Creator should be able to reopen their own {$status->value} ticket",
            );
            $this->assertTrue(
                $policy->reopen($superAdmin, $ticket),
                "super_admin should be able to reopen a {$status->value} ticket",
            );
            $this->assertFalse(
                $policy->reopen($stranger, $ticket),
                "A stranger should not be able to reopen someone else's {$status->value} ticket",
            );
        }
    }

    public function test_reopen_denied_for_non_eligible_statuses_including_cancelled(): void
    {
        $area    = Area::factory()->create(['name' => 'REO-NO', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $creator = User::factory()->create();
        $creator->assignRole('technician');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $policy = new TicketPolicy();

        // CANCELLED is included here on purpose: even though the user is
        // creator-or-super_admin, the policy refuses up-front because the
        // matrix has no path back from CANCELLED. Non-terminal statuses are
        // also rejected — there's nothing to "reopen" yet.
        foreach ([
            TaskStatusEnum::OPEN,
            TaskStatusEnum::ASSIGNED,
            TaskStatusEnum::IN_PROGRESS,
            TaskStatusEnum::ON_HOLD,
            TaskStatusEnum::CANCELLED,
        ] as $status) {
            $ticket = Ticket::factory()->create([
                'area_id'     => $area->id,
                'sub_area_id' => $subArea->id,
                'unit_id'     => $unit->id,
                'created_by'  => $creator->id,
                'status'      => $status,
            ]);

            $this->assertFalse(
                $policy->reopen($creator, $ticket),
                "Creator should not be able to reopen a {$status->value} ticket (only RESOLVED/CLOSED are eligible)",
            );
            $this->assertFalse(
                $policy->reopen($superAdmin, $ticket),
                "super_admin should not be able to reopen a {$status->value} ticket (only RESOLVED/CLOSED are eligible)",
            );
        }
    }
}
