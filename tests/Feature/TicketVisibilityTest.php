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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_view_own_sees_only_own_and_assigned(): void
    {
        $area    = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $tech     = User::factory()->create(['email' => 'tech@test.com']);
        $tech->assignRole('technician');
        $employee = Employee::factory()->create(['email' => 'tech@test.com']);

        // Owned by tech
        $own = Ticket::factory()->create([
            'created_by' => $tech->id, 'area_id' => $area->id,
            'sub_area_id' => $subArea->id, 'unit_id' => $unit->id,
        ]);

        // Assigned to tech
        $assigned = Ticket::factory()->create([
            'created_by' => 1, 'employee_id' => $employee->id,
            'area_id' => $area->id, 'sub_area_id' => $subArea->id, 'unit_id' => $unit->id,
        ]);

        // Unrelated
        $other = Ticket::factory()->create([
            'created_by' => 1, 'area_id' => $area->id,
            'sub_area_id' => $subArea->id, 'unit_id' => $unit->id,
        ]);

        $visible = (new Ticket)->newQuery()->visibleBy($tech)->pluck('id')->all();

        $this->assertContains($own->id, $visible);
        $this->assertContains($assigned->id, $visible);
        $this->assertNotContains($other->id, $visible);
    }

    public function test_view_group_sees_region_tickets(): void
    {
        $area    = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $supervisor = User::factory()->create(['email' => 'sup@test.com']);
        $supervisor->assignRole('supervisor');

        $supEmp = Employee::factory()->create(['email' => 'sup@test.com']);
        $group  = Group::factory()->create(['area_id' => $area->id, 'employee_id' => $supEmp->id]);
        GroupMember::factory()->create(['group_id' => $group->id, 'employee_id' => $supEmp->id]);

        // Ticket in the supervised area
        $inArea = Ticket::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $subArea->id, 'unit_id' => $unit->id,
            'created_by' => 1,
        ]);

        // Ticket in a different area
        $otherArea = Area::factory()->create();
        $otherSub  = SubArea::factory()->create(['area_id' => $otherArea->id]);
        $outArea   = Ticket::factory()->create([
            'area_id' => $otherArea->id, 'sub_area_id' => $otherSub->id, 'unit_id' => $unit->id,
            'created_by' => 1,
        ]);

        $visible = (new Ticket)->newQuery()->visibleBy($supervisor)->pluck('id')->all();

        $this->assertContains($inArea->id, $visible);
        $this->assertNotContains($outArea->id, $visible);
    }

    public function test_view_all_sees_everything(): void
    {
        $area    = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $a = Ticket::factory()->create(['area_id' => $area->id, 'sub_area_id' => $subArea->id, 'unit_id' => $unit->id, 'created_by' => 1]);
        $b = Ticket::factory()->create(['area_id' => $area->id, 'sub_area_id' => $subArea->id, 'unit_id' => $unit->id, 'created_by' => 2]);

        $visible = (new Ticket)->newQuery()->visibleBy($admin)->pluck('id')->all();

        $this->assertContains($a->id, $visible);
        $this->assertContains($b->id, $visible);
    }

    public function test_supervisor_via_group_employee_id_sees_managed_area_tickets(): void
    {
        // Supervisor is identified by groups.employee_id — NOT via a group_members row.
        // scopeVisibleBy() must derive area visibility from the managed group, not just
        // group membership.
        $area    = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $supervisor = User::factory()->create(['email' => 'grpsup@test.com']);
        $supervisor->assignRole('supervisor');
        $supEmp = Employee::factory()->create(['email' => 'grpsup@test.com']);

        // Group supervised by $supEmp — no GroupMember row for the supervisor
        Group::factory()->create(['area_id' => $area->id, 'employee_id' => $supEmp->id]);

        $inArea = Ticket::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $subArea->id, 'unit_id' => $unit->id,
            'created_by' => 1,
        ]);

        $otherArea = Area::factory()->create();
        $otherSub  = SubArea::factory()->create(['area_id' => $otherArea->id]);
        $outArea   = Ticket::factory()->create([
            'area_id' => $otherArea->id, 'sub_area_id' => $otherSub->id, 'unit_id' => $unit->id,
            'created_by' => 1,
        ]);

        $visible = (new Ticket)->newQuery()->visibleBy($supervisor)->pluck('id')->all();

        $this->assertContains($inArea->id, $visible, 'Supervisor must see tickets in managed group area');
        $this->assertNotContains($outArea->id, $visible, 'Supervisor must not see unrelated area tickets');
    }

    public function test_supervisor_sees_tickets_by_group_id_even_without_area_match(): void
    {
        // A ticket assigned to the managed group (group_id set) but in an area not
        // directly covered by any membership row must still be visible to the supervisor.
        $area    = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $supervisor = User::factory()->create(['email' => 'grpsup2@test.com']);
        $supervisor->assignRole('supervisor');
        $supEmp = Employee::factory()->create(['email' => 'grpsup2@test.com']);

        $managedGroup = Group::factory()->create(['area_id' => $area->id, 'employee_id' => $supEmp->id]);

        // Ticket explicitly points at the managed group — no GroupMember row
        $byGroup = Ticket::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $subArea->id, 'unit_id' => $unit->id,
            'group_id' => $managedGroup->id, 'created_by' => 1,
        ]);

        // Ticket in a completely different group / area
        $otherArea  = Area::factory()->create();
        $otherSub   = SubArea::factory()->create(['area_id' => $otherArea->id]);
        $otherGroup = Group::factory()->create(['area_id' => $otherArea->id]);
        $outGroup   = Ticket::factory()->create([
            'area_id' => $otherArea->id, 'sub_area_id' => $otherSub->id, 'unit_id' => $unit->id,
            'group_id' => $otherGroup->id, 'created_by' => 1,
        ]);

        $visible = (new Ticket)->newQuery()->visibleBy($supervisor)->pluck('id')->all();

        $this->assertContains($byGroup->id, $visible, 'Supervisor must see tickets with matching group_id');
        $this->assertNotContains($outGroup->id, $visible, 'Supervisor must not see tickets from unmanaged groups');
    }

    public function test_supervisor_without_managed_group_does_not_see_others_group_tickets(): void
    {
        // A user with ticket.view.group but no managed-group record must not gain
        // visibility via the supervisor path (only member path applies).
        $area    = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $user = User::factory()->create(['email' => 'nomgr@test.com']);
        $user->assignRole('supervisor');
        Employee::factory()->create(['email' => 'nomgr@test.com']);

        // A group whose supervisor is someone else
        $otherEmp   = Employee::factory()->create();
        $otherGroup = Group::factory()->create(['area_id' => $area->id, 'employee_id' => $otherEmp->id]);

        $ticket = Ticket::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $subArea->id, 'unit_id' => $unit->id,
            'group_id' => $otherGroup->id, 'created_by' => 1,
        ]);

        $visible = (new Ticket)->newQuery()->visibleBy($user)->pluck('id')->all();

        $this->assertNotContains($ticket->id, $visible, 'User must not see tickets from groups they do not supervise');
    }

    public function test_no_permission_sees_nothing(): void
    {
        $area    = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $nobody = User::factory()->create();
        $nobody->assignRole('default');

        Ticket::factory()->count(3)->create([
            'area_id' => $area->id, 'sub_area_id' => $subArea->id, 'unit_id' => $unit->id, 'created_by' => 1,
        ]);

        $count = (new Ticket)->newQuery()->visibleBy($nobody)->count();
        $this->assertSame(0, $count);
    }
}
