<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Pages\PerformanceDashboard;
use App\Models\Area;
use App\Models\Employee;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        // Placeholder actor so model boot hooks (created_by) don't fail.
        $this->actingAs(User::factory()->create());
    }

    /**
     * Helper: create Employee + User sharing an email (Employee::user() pattern).
     *
     * @return array{Employee, User}
     */
    private function makeEmployeeUser(string $tag, string $role = 'supervisor'): array
    {
        $email    = "perf-dash-{$tag}@test.com";
        $employee = Employee::factory()->create([
            'email'  => $email,
            'status' => ActiveStatusEnum::ACTIVE,
        ]);
        $user = User::factory()->create(['email' => $email, 'username' => "perf-{$tag}"]);
        $user->assignRole($role);
        return [$employee, $user];
    }

    private function makeTicketInArea(Area $area): Ticket
    {
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        return Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::OPEN,
        ]);
    }

    /**
     * A user who is a supervisor ONLY via groups.employee_id (no group_members row)
     * must see a non-empty teamStats after loadStats(). This was broken before the
     * fix: the old branch only queried group_members, missing the employee_id path.
     */
    public function test_supervisor_via_groups_employee_id_gets_non_empty_team_stats(): void
    {
        [$supEmp, $supUser] = $this->makeEmployeeUser('sup-manager');

        // A separate technician who IS in group_members (so getTeamStats returns them).
        [$techEmp, $techUser] = $this->makeEmployeeUser('tech', 'technician');

        $area  = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);

        // Supervisor is the group manager only (groups.employee_id) — no GroupMember row.
        $group = Group::factory()->create([
            'area_id'     => $area->id,
            'employee_id' => $supEmp->id,
        ]);

        // Technician is a member so getTeamStats finds them via GroupMember.
        GroupMember::factory()->create([
            'group_id'    => $group->id,
            'employee_id' => $techEmp->id,
        ]);

        $this->makeTicketInArea($area);

        $this->actingAs($supUser);

        $page = new PerformanceDashboard();
        $page->mount();

        $this->assertNotEmpty(
            $page->teamStats,
            'Supervisor via groups.employee_id should see non-empty teamStats'
        );
    }

    /**
     * Regression: a user who has no group membership AND no managed group
     * must still get an empty teamStats (not crash or leak data).
     */
    public function test_user_with_no_group_gets_empty_team_stats(): void
    {
        [$noGroupEmp, $noGroupUser] = $this->makeEmployeeUser('no-group');

        // No Group row references this employee at all.
        $area = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $this->makeTicketInArea($area);

        $this->actingAs($noGroupUser);

        $page = new PerformanceDashboard();
        $page->mount();

        $this->assertEmpty(
            $page->teamStats,
            'User with no group membership or managed group should have empty teamStats'
        );
    }
}
