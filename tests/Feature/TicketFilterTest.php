<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Company $company;
    protected Area $area;
    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
        $this->actingAs($this->admin);

        $this->company = Company::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $this->area    = Area::factory()->create([
            'company_id' => $this->company->id,
            'status'     => ActiveStatusEnum::ACTIVE,
        ]);
        $this->unit = Unit::factory()->create();
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function makeTicket(array $attrs = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'area_id'    => $this->area->id,
            'unit_id'    => $this->unit->id,
            'priority'   => TaskPriorityEnum::Medium,
            'status'     => TaskStatusEnum::OPEN,
            'created_by' => $this->admin->id,
        ], $attrs));
    }

    /** Force-write column values, bypassing observers. */
    private function forceSet(Ticket $ticket, array $attrs): void
    {
        $ticket->forceFill($attrs)->saveQuietly();
    }

    // ──────────────────────────────────────────────────────────────────
    // sla_status filter
    // ──────────────────────────────────────────────────────────────────

    public function test_sla_status_breached_returns_only_breached_tickets(): void
    {
        $breached = $this->makeTicket(['status' => TaskStatusEnum::IN_PROGRESS]);
        $this->forceSet($breached, ['sla_breached' => true, 'sla_deadline' => now()->subHour()]);

        $normal = $this->makeTicket();
        $this->forceSet($normal, ['sla_breached' => false]);

        $ids = $this->applySlaFilter(['breached']);

        $this->assertContains($breached->id, $ids, 'Breached ticket should appear with breached filter.');
        $this->assertNotContains($normal->id, $ids, 'Non-breached ticket should not appear with breached filter.');
    }

    public function test_sla_status_no_sla_returns_only_null_deadline_tickets(): void
    {
        $noSla = $this->makeTicket();
        $this->forceSet($noSla, ['sla_deadline' => null, 'sla_breached' => false]);

        $withSla = $this->makeTicket();
        $this->forceSet($withSla, ['sla_deadline' => now()->addHour(), 'sla_breached' => false]);

        $ids = $this->applySlaFilter(['no_sla']);

        $this->assertContains($noSla->id, $ids, 'No-SLA ticket should appear with no_sla filter.');
        $this->assertNotContains($withSla->id, $ids, 'Ticket with deadline should not appear with no_sla filter.');
    }

    public function test_sla_status_both_values_returns_union(): void
    {
        $breached = $this->makeTicket(['status' => TaskStatusEnum::IN_PROGRESS]);
        $this->forceSet($breached, ['sla_breached' => true, 'sla_deadline' => now()->subHour()]);

        $noSla = $this->makeTicket();
        $this->forceSet($noSla, ['sla_deadline' => null, 'sla_breached' => false]);

        $withSla = $this->makeTicket();
        $this->forceSet($withSla, ['sla_deadline' => now()->addHour(), 'sla_breached' => false]);

        $ids = $this->applySlaFilter(['breached', 'no_sla']);

        $this->assertContains($breached->id, $ids, 'Breached ticket should appear in union.');
        $this->assertContains($noSla->id, $ids, 'No-SLA ticket should appear in union.');
        $this->assertNotContains($withSla->id, $ids, 'On-time ticket with deadline should not appear in union.');
    }

    /** Reproduce the sla_status filter query logic. */
    private function applySlaFilter(array $values): array
    {
        return Ticket::query()
            ->where(function (Builder $q) use ($values) {
                if (in_array('breached', $values)) {
                    $q->orWhere('sla_breached', true);
                }
                if (in_array('no_sla', $values)) {
                    $q->orWhereNull('sla_deadline');
                }
            })
            ->pluck('id')
            ->all();
    }

    // ──────────────────────────────────────────────────────────────────
    // employee_id filter options scoping
    // ──────────────────────────────────────────────────────────────────

    public function test_employee_filter_options_for_super_admin_include_all_active_employees(): void
    {
        $emp1 = Employee::factory()->create(['status' => ActiveStatusEnum::ACTIVE, 'company_id' => $this->company->id]);
        $emp2 = Employee::factory()->create(['status' => ActiveStatusEnum::ACTIVE, 'company_id' => $this->company->id]);
        $inactive = Employee::factory()->create(['status' => ActiveStatusEnum::INACTIVE, 'company_id' => $this->company->id]);

        // super_admin branch
        $options = Employee::query()
            ->where('status', ActiveStatusEnum::ACTIVE)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();

        $this->assertArrayHasKey($emp1->id, $options);
        $this->assertArrayHasKey($emp2->id, $options);
        $this->assertArrayNotHasKey($inactive->id, $options);
    }

    public function test_employee_filter_options_for_group_scoped_user_sees_only_group_members(): void
    {
        $supervisor = Employee::factory()->create([
            'email'      => 'sup-filter@test.com',
            'status'     => ActiveStatusEnum::ACTIVE,
            'company_id' => $this->company->id,
        ]);
        $supUser = User::factory()->create(['email' => 'sup-filter@test.com']);
        $supUser->givePermissionTo(['view_any_ticket', 'view_ticket', 'ticket.view.group']);

        $group = Group::factory()->create([
            'area_id'     => $this->area->id,
            'company_id'  => $this->company->id,
            'unit_id'     => $this->unit->id,
            'employee_id' => $supervisor->id,
            'status'      => ActiveStatusEnum::ACTIVE,
        ]);

        $memberEmp = Employee::factory()->create([
            'status'     => ActiveStatusEnum::ACTIVE,
            'company_id' => $this->company->id,
        ]);
        GroupMember::factory()->create(['group_id' => $group->id, 'employee_id' => $memberEmp->id]);

        $outsideEmp = Employee::factory()->create([
            'status'     => ActiveStatusEnum::ACTIVE,
            'company_id' => $this->company->id,
        ]);
        // outsideEmp has no group membership

        $this->actingAs($supUser);

        // Reproduce the ticket.view.group branch of the employee_id options closure.
        $employeeId = $supUser->employee?->id;
        $this->assertNotNull($employeeId, 'Supervisor user must have an employee record linked by email.');

        $groupIds = \App\Models\GroupMember::where('employee_id', $employeeId)
            ->pluck('group_id')
            ->merge(Group::where('employee_id', $employeeId)->pluck('id'))
            ->unique();

        $options = Employee::query()
            ->where('status', ActiveStatusEnum::ACTIVE)
            ->whereHas('groupMemberships', fn ($q) => $q->whereIn('group_id', $groupIds))
            ->pluck('name', 'id')
            ->toArray();

        $this->assertArrayHasKey($memberEmp->id, $options, 'Group member should appear in scoped options.');
        $this->assertArrayNotHasKey($outsideEmp->id, $options, 'Employee outside the group should not appear.');
    }

    // ──────────────────────────────────────────────────────────────────
    // company_id filter query
    // ──────────────────────────────────────────────────────────────────

    public function test_company_filter_scopes_tickets_to_selected_company(): void
    {
        $companyB = Company::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $areaB    = Area::factory()->create(['company_id' => $companyB->id, 'status' => ActiveStatusEnum::ACTIVE]);

        $ticketA = $this->makeTicket();                           // area_id → company A
        $ticketB = Ticket::factory()->create([                    // area_id → company B
            'area_id'    => $areaB->id,
            'unit_id'    => $this->unit->id,
            'priority'   => TaskPriorityEnum::Medium,
            'status'     => TaskStatusEnum::OPEN,
            'created_by' => $this->admin->id,
        ]);

        // Filter for company B only
        $ids = Ticket::query()
            ->whereHas('area', fn ($q) => $q->whereIn('company_id', [$companyB->id]))
            ->pluck('id')
            ->all();

        $this->assertContains($ticketB->id, $ids, 'Company B ticket should appear when filtering for company B.');
        $this->assertNotContains($ticketA->id, $ids, 'Company A ticket should not appear when filtering for company B.');
    }

    public function test_company_filter_visibility_is_false_for_view_group_user(): void
    {
        $groupUser = User::factory()->create();
        $groupUser->givePermissionTo(['view_any_ticket', 'view_ticket', 'ticket.view.group']);

        $this->actingAs($groupUser);

        $visible = $groupUser->hasRole('super_admin') || $groupUser->can('ticket.view.all');

        $this->assertFalse($visible,
            'company_id filter should be hidden for ticket.view.group users.');
    }

    public function test_company_filter_visibility_is_true_for_super_admin(): void
    {
        $this->actingAs($this->admin); // super_admin

        $visible = $this->admin->hasRole('super_admin') || $this->admin->can('ticket.view.all');

        $this->assertTrue($visible,
            'company_id filter should be visible for super_admin.');
    }

    // ──────────────────────────────────────────────────────────────────
    // task_date filter
    // ──────────────────────────────────────────────────────────────────

    public function test_task_date_from_bound_excludes_earlier_tickets(): void
    {
        $old   = $this->makeTicket(['task_date' => '2026-01-01']);
        $fresh = $this->makeTicket(['task_date' => '2026-03-15']);

        $ids = Ticket::query()
            ->whereDate('task_date', '>=', '2026-03-01')
            ->pluck('id')
            ->all();

        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    public function test_task_date_until_bound_excludes_later_tickets(): void
    {
        $old   = $this->makeTicket(['task_date' => '2026-01-10']);
        $fresh = $this->makeTicket(['task_date' => '2026-05-01']);

        $ids = Ticket::query()
            ->whereDate('task_date', '<=', '2026-02-28')
            ->pluck('id')
            ->all();

        $this->assertContains($old->id, $ids);
        $this->assertNotContains($fresh->id, $ids);
    }

    public function test_task_date_range_both_bounds(): void
    {
        $before  = $this->makeTicket(['task_date' => '2026-01-05']);
        $inside  = $this->makeTicket(['task_date' => '2026-02-15']);
        $after   = $this->makeTicket(['task_date' => '2026-04-01']);

        $ids = Ticket::query()
            ->whereDate('task_date', '>=', '2026-02-01')
            ->whereDate('task_date', '<=', '2026-03-31')
            ->pluck('id')
            ->all();

        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    // ──────────────────────────────────────────────────────────────────
    // sub_area_id filter
    // ──────────────────────────────────────────────────────────────────

    public function test_sub_area_filter_returns_only_matching_tickets(): void
    {
        $subAreaA = SubArea::factory()->create(['area_id' => $this->area->id]);
        $subAreaB = SubArea::factory()->create(['area_id' => $this->area->id]);

        $ticketA = $this->makeTicket(['sub_area_id' => $subAreaA->id]);
        $ticketB = $this->makeTicket(['sub_area_id' => $subAreaB->id]);

        $ids = Ticket::query()
            ->whereIn('sub_area_id', [$subAreaA->id])
            ->pluck('id')
            ->all();

        $this->assertContains($ticketA->id, $ids);
        $this->assertNotContains($ticketB->id, $ids);
    }

    // ──────────────────────────────────────────────────────────────────
    // unit_id filter
    // ──────────────────────────────────────────────────────────────────

    public function test_unit_filter_returns_only_matching_tickets(): void
    {
        $unitA = Unit::factory()->create();
        $unitB = Unit::factory()->create();

        $ticketA = $this->makeTicket(['unit_id' => $unitA->id]);
        $ticketB = $this->makeTicket(['unit_id' => $unitB->id]);

        $ids = Ticket::query()
            ->whereIn('unit_id', [$unitA->id])
            ->pluck('id')
            ->all();

        $this->assertContains($ticketA->id, $ids);
        $this->assertNotContains($ticketB->id, $ids);
    }
}
