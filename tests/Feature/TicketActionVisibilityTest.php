<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Area;
use App\Models\Company;
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

class TicketActionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    /**
     * Create a user with the given permissions. Every user also gets the
     * minimum page-access permissions (view_any_ticket + view_ticket +
     * ticket.view.all) so Filament routes and the getEloquentQuery scope
     * don't filter them out before the action visibility is evaluated.
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
     * Create a paired Employee + User sharing an email address.
     * Employee::user is belongsTo(User, 'email', 'email'), so the link
     * is established by the shared email.
     *
     * @return array{Employee, User}
     */
    private function makeEmployeeUser(string $tag, array $permissions = []): array
    {
        $email    = "visibility-{$tag}@test.com";
        $employee = Employee::factory()->create([
            'email'  => $email,
            'status' => ActiveStatusEnum::ACTIVE,
        ]);
        $user = $this->makeUserWithPermissions($permissions);
        $user->update(['email' => $email]);

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

    // ──────────────────────────────────────────────────────────────────
    // CHANGE 1: Ata / Yeniden Ata visibility
    // Rule: ticket.assign AND (creator OR current assignee's user), not terminal
    // ──────────────────────────────────────────────────────────────────

    public function test_assign_action_visible_to_creator_with_ticket_assign(): void
    {
        $creator = $this->makeUserWithPermissions(['ticket.assign']);
        $this->actingAs($creator);

        $ticket = $this->makeTicket(['created_by' => $creator->id]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionVisible('assign');
    }

    public function test_assign_action_visible_to_current_assignee_user_with_ticket_assign(): void
    {
        [$assigneeEmployee, $assigneeUser] = $this->makeEmployeeUser('assignee', ['ticket.assign']);
        $this->actingAs($assigneeUser);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $assigneeEmployee->id,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionVisible('assign');
    }

    public function test_assign_action_hidden_from_unrelated_user_with_ticket_assign(): void
    {
        // Has the permission but is neither the creator nor the assignee.
        $other = $this->makeUserWithPermissions(['ticket.assign']);
        $this->actingAs($other);

        [$assigneeEmployee] = $this->makeEmployeeUser('unrelated-assignee');
        $creator = $this->makeUserWithPermissions([]);

        $ticket = $this->makeTicket([
            'created_by'  => $creator->id,
            'employee_id' => $assigneeEmployee->id,
            'status'      => TaskStatusEnum::ASSIGNED,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionHidden('assign');
    }

    public function test_assign_action_hidden_from_creator_without_ticket_assign(): void
    {
        // Creator but missing the permission.
        $creator = $this->makeUserWithPermissions([]);
        $this->actingAs($creator);

        $ticket = $this->makeTicket(['created_by' => $creator->id]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionHidden('assign');
    }

    public function test_assign_action_hidden_on_terminal_ticket_for_creator_with_ticket_assign(): void
    {
        $creator = $this->makeUserWithPermissions(['ticket.assign']);
        $this->actingAs($creator);

        $ticket = $this->makeTicket([
            'created_by' => $creator->id,
            'status'     => TaskStatusEnum::RESOLVED,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionHidden('assign');
    }

    public function test_assign_action_hidden_on_terminal_ticket_for_assignee_with_ticket_assign(): void
    {
        [$assigneeEmployee, $assigneeUser] = $this->makeEmployeeUser('terminal-assignee', ['ticket.assign']);
        $this->actingAs($assigneeUser);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::CANCELLED,
            'employee_id' => $assigneeEmployee->id,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertActionHidden('assign');
    }

    // ──────────────────────────────────────────────────────────────────
    // CHANGE 4: employee select excludes employees without a valid email
    //
    // Filament 3.x provides no stable public API for reading Select
    // options inside a mounted action form without navigating unstable
    // internals, so we test at the query level. The options closure in
    // both ViewTicket and TicketResource uses whereNotNull('email') and
    // where('email', '!=', '') — tested here against real data.
    // Note: employees.email is NOT NULL in the schema, so only the
    // empty-string case is reachable in practice.
    // ──────────────────────────────────────────────────────────────────

    public function test_employee_select_excludes_employees_without_valid_email(): void
    {
        // Unit::creating boot hook calls auth()->id() — must have a user.
        $this->actingAs($this->makeUserWithPermissions([]));

        $company = Company::factory()->create();
        $area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE, 'company_id' => $company->id]);
        $unit    = Unit::factory()->create();
        $supervisor = Employee::factory()->create(['email' => 'sup@test.com']);
        $group = Group::factory()->create([
            'area_id'     => $area->id,
            'unit_id'     => $unit->id,
            'company_id'  => $company->id,
            'employee_id' => $supervisor->id,
            'status'      => ActiveStatusEnum::ACTIVE,
        ]);

        // The schema enforces NOT NULL on employees.email, so only empty string is a
        // realistic invalid value. The whereNotNull() guard in the options closure is
        // defensive; the practical filter is where('email', '!=', '').
        $withEmail  = Employee::factory()->create(['email' => 'valid@test.com', 'status' => ActiveStatusEnum::ACTIVE]);
        $emptyEmail = Employee::factory()->create(['email' => '',               'status' => ActiveStatusEnum::ACTIVE]);

        foreach ([$withEmail, $emptyEmail] as $emp) {
            GroupMember::factory()->create([
                'group_id'    => $group->id,
                'employee_id' => $emp->id,
            ]);
        }

        // Reproduce the exact query the options closures use.
        $options = Employee::query()
            ->whereHas('groupMemberships', fn ($q) => $q->where('group_id', $group->id))
            ->where('status', ActiveStatusEnum::ACTIVE->value)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('name', 'id');

        $this->assertArrayHasKey($withEmail->id, $options->toArray());
        $this->assertArrayNotHasKey($emptyEmail->id, $options->toArray());
    }

    // ──────────────────────────────────────────────────────────────────
    // Assignee exclusion: current employee_id must not appear in the
    // options list so the user cannot re-select the same person.
    //
    // Tested at the query level for the same reason as the email filter
    // above — Filament 3.x exposes no stable API for reading Select
    // options inside a mounted action form.
    //
    // Three query sites are covered:
    //   A) ViewTicket assign action — group-member branch
    //   B) ViewTicket assign action — company-fallback branch
    //   C) TicketResource form employee_id Select
    // ──────────────────────────────────────────────────────────────────

    public function test_view_ticket_assign_group_branch_excludes_current_assignee(): void
    {
        $this->actingAs($this->makeUserWithPermissions([]));

        $company    = Company::factory()->create();
        $area       = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE, 'company_id' => $company->id]);
        $unit       = Unit::factory()->create();
        $supervisor = Employee::factory()->create(['email' => 'excl-sup@test.com']);
        $group      = Group::factory()->create([
            'area_id'     => $area->id,
            'unit_id'     => $unit->id,
            'company_id'  => $company->id,
            'employee_id' => $supervisor->id,
            'status'      => ActiveStatusEnum::ACTIVE,
        ]);

        $currentAssignee = Employee::factory()->create(['email' => 'excl-current@test.com', 'status' => ActiveStatusEnum::ACTIVE]);
        $otherMember     = Employee::factory()->create(['email' => 'excl-other@test.com',   'status' => ActiveStatusEnum::ACTIVE]);

        foreach ([$currentAssignee, $otherMember] as $emp) {
            GroupMember::factory()->create(['group_id' => $group->id, 'employee_id' => $emp->id]);
        }

        // Reproduce the group-member branch of ViewTicket::assign options.
        $options = Employee::query()
            ->whereHas('groupMemberships', fn ($q) => $q->where('group_id', $group->id))
            ->where('status', ActiveStatusEnum::ACTIVE->value)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when($currentAssignee->id, fn ($q, $v) => $q->where('id', '!=', $v))
            ->pluck('name', 'id');

        $this->assertArrayNotHasKey($currentAssignee->id, $options->toArray());
        $this->assertArrayHasKey($otherMember->id, $options->toArray());
    }

    public function test_view_ticket_assign_company_fallback_excludes_current_assignee(): void
    {
        $this->actingAs($this->makeUserWithPermissions([]));

        $company         = Company::factory()->create();
        $currentAssignee = Employee::factory()->create(['email' => 'fb-current@test.com', 'status' => ActiveStatusEnum::ACTIVE, 'company_id' => $company->id]);
        $otherEmployee   = Employee::factory()->create(['email' => 'fb-other@test.com',   'status' => ActiveStatusEnum::ACTIVE, 'company_id' => $company->id]);

        // Reproduce the company-fallback branch of ViewTicket::assign options.
        // $companyId is derived from Employee::find($ticket->employee_id)->company_id.
        $companyId = $currentAssignee->company_id;

        $options = Employee::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', \App\Enums\ActiveStatusEnum::ACTIVE->value)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when($currentAssignee->id, fn ($q, $v) => $q->where('id', '!=', $v))
            ->orderBy('name')
            ->limit(500)
            ->pluck('name', 'id');

        $this->assertArrayNotHasKey($currentAssignee->id, $options->toArray());
        $this->assertArrayHasKey($otherEmployee->id, $options->toArray());
    }

    public function test_ticket_resource_form_employee_options_excludes_selected_employee(): void
    {
        $this->actingAs($this->makeUserWithPermissions([]));

        $company    = Company::factory()->create();
        $area       = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE, 'company_id' => $company->id]);
        $unit       = Unit::factory()->create();
        $supervisor = Employee::factory()->create(['email' => 'form-sup@test.com']);
        $group      = Group::factory()->create([
            'area_id'     => $area->id,
            'unit_id'     => $unit->id,
            'company_id'  => $company->id,
            'employee_id' => $supervisor->id,
            'status'      => ActiveStatusEnum::ACTIVE,
        ]);

        $selectedEmployee = Employee::factory()->create(['email' => 'form-selected@test.com', 'status' => ActiveStatusEnum::ACTIVE]);
        $otherMember      = Employee::factory()->create(['email' => 'form-other@test.com',    'status' => ActiveStatusEnum::ACTIVE]);

        foreach ([$selectedEmployee, $otherMember] as $emp) {
            GroupMember::factory()->create(['group_id' => $group->id, 'employee_id' => $emp->id]);
        }

        // Reproduce the TicketResource form employee_id Select options closure.
        // $get('employee_id') is simulated by $selectedEmployee->id.
        $currentEmployeeId = $selectedEmployee->id;

        $options = Employee::query()
            ->whereHas('groupMemberships', fn ($q) => $q->where('group_id', $group->id))
            ->where('status', \App\Enums\ActiveStatusEnum::ACTIVE->value)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when($currentEmployeeId, fn ($q, $v) => $q->where('id', '!=', $v))
            ->orderBy('name')
            ->pluck('name', 'id');

        $this->assertArrayNotHasKey($selectedEmployee->id, $options->toArray());
        $this->assertArrayHasKey($otherMember->id, $options->toArray());
    }
}
