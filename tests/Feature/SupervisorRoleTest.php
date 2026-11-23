<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\UserStatusEnum;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\SupervisorRoleService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Ekip Amiri" role follows the Ekipler screen: given when someone
 * becomes a team's supervisor, taken back when they supervise no team.
 */
class SupervisorRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->actingAs(User::factory()->create());
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

    private function isSupervisor(User $user): bool
    {
        return $user->fresh()->hasRole(SupervisorRoleService::ROLE);
    }

    public function test_choosing_a_supervisor_gives_the_role(): void
    {
        [$employee, $user] = $this->person();
        [, $member] = $this->person();

        Team::factory()->create(['supervisor_employee_id' => $employee->id]);

        $this->assertTrue($this->isSupervisor($user));
        $this->assertFalse($this->isSupervisor($member));
    }

    public function test_replaced_supervisor_loses_the_role_unless_they_lead_another_team(): void
    {
        [$first, $firstUser] = $this->person();
        [$second, $secondUser] = $this->person();
        $team = Team::factory()->create(['supervisor_employee_id' => $first->id]);
        $other = Team::factory()->create(['supervisor_employee_id' => $first->id]);

        $team->update(['supervisor_employee_id' => $second->id]);

        $this->assertTrue($this->isSupervisor($secondUser));
        $this->assertTrue($this->isSupervisor($firstUser), 'still leads another team');

        $other->update(['supervisor_employee_id' => $second->id]);

        $this->assertFalse($this->isSupervisor($firstUser));
    }

    public function test_deleting_the_team_takes_the_role_back(): void
    {
        [$employee, $user] = $this->person();
        $team = Team::factory()->create(['supervisor_employee_id' => $employee->id]);

        $team->delete();

        $this->assertFalse($this->isSupervisor($user));
    }

    public function test_supervisor_without_an_account_gets_the_role_on_first_login(): void
    {
        $employee = Employee::factory()->create(['email' => 'yeni.amir@example.com', 'status' => ActiveStatusEnum::ACTIVE]);
        Team::factory()->create(['supervisor_employee_id' => $employee->id]);

        $user = User::factory()->create(['email' => 'yeni.amir@example.com']);
        app(SupervisorRoleService::class)->syncUser($user);

        $this->assertTrue($this->isSupervisor($user));
    }

    public function test_a_person_with_two_employee_records_is_one_supervisor(): void
    {
        [$employee, $user] = $this->person();
        $duplicate = Employee::factory()->create(['email' => $employee->email, 'status' => ActiveStatusEnum::ACTIVE]);
        $team = Team::factory()->create(['supervisor_employee_id' => $duplicate->id]);
        $this->assertTrue($this->isSupervisor($user));

        $team->delete();

        $this->assertFalse($this->isSupervisor($user));
    }

    public function test_migration_creates_the_role_and_syncs_current_supervisors(): void
    {
        [$employee, $user] = $this->person();
        Team::withoutEvents(fn () => Team::factory()->create(['supervisor_employee_id' => $employee->id]));
        [, $formerSupervisor] = $this->person();
        $formerSupervisor->assignRole(Role::findOrCreate(SupervisorRoleService::ROLE, 'web'));

        (require database_path('migrations/2026_11_19_175811_add_team_supervisor_role.php'))->up();

        $role = Role::findByName(SupervisorRoleService::ROLE);
        $this->assertTrue($role->hasPermissionTo('widget_RequestsByStatusChart'));
        $this->assertTrue($role->hasPermissionTo('view_any_team::ticket'));
        $this->assertTrue($this->isSupervisor($user));
        $this->assertFalse($this->isSupervisor($formerSupervisor));
    }
}
