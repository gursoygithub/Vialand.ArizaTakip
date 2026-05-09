<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Area;
use App\Models\Employee;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketAssignActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        // Authenticate a placeholder so Area/SubArea/Unit factories that rely
        // on auth()->id() in their booted() hooks don't fail.
        $this->actingAs(User::factory()->create());
    }

    /**
     * Give a user the minimum permissions to reach the ViewTicket page plus
     * any extra permissions supplied by the caller.
     */
    private function makeUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_unique(array_merge(
            ['view_any_ticket', 'view_ticket', 'ticket.view.all'],
            $permissions,
        )));
        return $user;
    }

    /**
     * Create a paired Employee + User sharing an email address so that
     * Employee::user() resolves correctly (belongsTo on email, whereNotNull('username')).
     *
     * @return array{Employee, User}
     */
    private function makeEmployeeUser(string $tag, array $permissions = []): array
    {
        $email    = "assign-action-{$tag}@test.com";
        $employee = Employee::factory()->create([
            'email'  => $email,
            'status' => ActiveStatusEnum::ACTIVE,
        ]);
        $user = $this->makeUserWithPermissions($permissions);
        // username must be set for Employee::user() (whereNotNull('username'))
        $user->update(['email' => $email, 'username' => 'assign-action-' . $tag]);
        return [$employee, $user];
    }

    private function makeTicket(array $overrides = []): Ticket
    {
        $area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        return Ticket::factory()->create(array_merge([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::OPEN,
        ], $overrides));
    }

    public function test_assign_action_visible_to_group_supervisor(): void
    {
        // Supervisor is identified by groups.employee_id — NOT a group_members row.
        // After d5c1a30, they can already see the ticket; this test ensures the
        // "Ata" button also appears for them when they hold ticket.assign.
        [$supEmp, $supUser] = $this->makeEmployeeUser('supervisor', ['ticket.assign']);
        $this->actingAs($supUser);

        $ticket  = $this->makeTicket(['created_by' => $supUser->id]);
        $group   = Group::factory()->create([
            'area_id'     => $ticket->area_id,
            'employee_id' => $supEmp->id,
        ]);
        $ticket->update(['group_id' => $group->id]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionVisible('assign');
    }

    public function test_assign_action_hidden_from_plain_group_member(): void
    {
        // A group member (GroupMember row) who is NOT the supervisor, NOT the
        // creator, and NOT the assignee must NOT see the assign button even if
        // they hold ticket.assign.
        [$memberEmp, $memberUser] = $this->makeEmployeeUser('member', ['ticket.assign']);
        $this->actingAs($memberUser);

        [$supEmp] = $this->makeEmployeeUser('sup-other');

        // Ticket created by someone else — member is neither creator nor assignee
        $otherCreator = $this->makeUserWithPermissions([]);
        $ticket = $this->makeTicket(['created_by' => $otherCreator->id]);

        $group = Group::factory()->create([
            'area_id'     => $ticket->area_id,
            'employee_id' => $supEmp->id,
        ]);
        $ticket->update(['group_id' => $group->id]);

        // Member of the group but NOT the supervisor
        GroupMember::factory()->create([
            'group_id'    => $group->id,
            'employee_id' => $memberEmp->id,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionHidden('assign');
    }

    public function test_assign_action_still_visible_to_creator_regression(): void
    {
        // Regression: existing creator visibility must be unaffected.
        $creator = $this->makeUserWithPermissions(['ticket.assign']);
        $this->actingAs($creator);

        // Explicit created_by so the creator check is reliable regardless of
        // TicketFactory's hardcoded created_by=1.
        $ticket = $this->makeTicket(['created_by' => $creator->id]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionVisible('assign');
    }

    public function test_assign_action_still_visible_to_current_assignee_regression(): void
    {
        // Regression: existing assignee visibility must be unaffected.
        // makeEmployeeUser sets username so Employee::user() (whereNotNull('username'))
        // resolves the User from the Employee record.
        [$assigneeEmp, $assigneeUser] = $this->makeEmployeeUser('assignee', ['ticket.assign']);
        $this->actingAs($assigneeUser);

        $otherCreator = $this->makeUserWithPermissions([]);
        $ticket = $this->makeTicket([
            'created_by'  => $otherCreator->id,
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $assigneeEmp->id,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionVisible('assign');
    }
}
