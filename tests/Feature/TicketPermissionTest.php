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

        $policy = new TicketPolicy();

        // TechnicianA cannot view TechnicianB's ticket
        $this->assertFalse($policy->view($technicianA, $ticket));
    }

    public function test_supervisor_can_view_their_regions_tickets(): void
    {
        $area    = Area::factory()->create(['name' => 'Y', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $supervisor = User::factory()->create(['email' => 'supervisor@test.com']);
        $supervisor->assignRole('supervisor');

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

        $policy = new TicketPolicy();

        $this->assertFalse($policy->close($technician, $ticket));
        $this->assertTrue($policy->close($supervisor, $ticket));
    }
}
