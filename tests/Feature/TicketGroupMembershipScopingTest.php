<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TicketResource;
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
use Tests\TestCase;

class TicketGroupMembershipScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        // Company (and other models) have created_by boot hooks that call
        // auth()->id(). Authenticate a placeholder so factory calls at the
        // top of each test don't fail with NOT NULL on created_by. Each test
        // then calls actingAs($user) to switch to the real test user.
        $this->actingAs(User::factory()->create());
    }

    /**
     * Create a linked employee+user pair in the given company.
     * Uses ticket.view.own (NOT ticket.view.all) so scopedCompanyIds()
     * returns the company ID and the getEloquentQuery company/membership
     * filter is actually evaluated.
     *
     * @return array{Employee, User}
     */
    private function makeEmployeeUserInCompany(int $companyId, string $emailTag): array
    {
        $email    = "gms-{$emailTag}@test.com";
        $employee = Employee::factory()->create([
            'email'      => $email,
            'company_id' => $companyId,
            'status'     => ActiveStatusEnum::ACTIVE,
        ]);
        $user = User::factory()->create(['email' => $email]);
        $user->givePermissionTo(['view_any_ticket', 'view_ticket', 'ticket.view.own']);

        return [$employee, $user];
    }

    /**
     * Create a group in the given area. Requires auth() for Group/GroupMember
     * booted() hooks — call actingAs() before using this helper.
     */
    private function makeGroupInArea(Area $area, Company $company): Group
    {
        $unit      = Unit::factory()->create();
        $supervisor = Employee::factory()->create(['email' => "gms-sup-{$area->id}@test.com"]);

        return Group::factory()->create([
            'area_id'     => $area->id,
            'company_id'  => $company->id,
            'unit_id'     => $unit->id,
            'employee_id' => $supervisor->id,
            'status'      => ActiveStatusEnum::ACTIVE,
        ]);
    }

    private function makeTicketInArea(Area $area, int $createdBy): Ticket
    {
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        return Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => $createdBy,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // getEloquentQuery() — ticket list scoping
    //
    // Tested by calling TicketResource::getEloquentQuery() directly.
    // The method applies scopeVisibleBy() then the company+membership OR;
    // we use ticket.view.own so scopeVisibleBy restricts to own/assigned
    // and scopedCompanyIds() returns [companyA_id] (not empty).
    // ──────────────────────────────────────────────────────────────────

    public function test_ticket_list_includes_tickets_in_group_membership_area_of_foreign_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        [$employee, $user] = $this->makeEmployeeUserInCompany($companyA->id, 'list-member');

        // actingAs before Group/GroupMember/Unit/Ticket creation (boot hooks need auth()->id())
        $this->actingAs($user);

        $foreignArea = Area::factory()->create(['company_id' => $companyB->id, 'status' => ActiveStatusEnum::ACTIVE]);
        $group       = $this->makeGroupInArea($foreignArea, $companyB);
        GroupMember::factory()->create(['group_id' => $group->id, 'employee_id' => $employee->id]);

        $ticket = $this->makeTicketInArea($foreignArea, $user->id);

        $ids = TicketResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($ticket->id, $ids,
            'Ticket in a foreign-company area where user has group membership should be visible.');
    }

    public function test_ticket_list_excludes_tickets_in_non_membership_foreign_area(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        [$employee, $user] = $this->makeEmployeeUserInCompany($companyA->id, 'list-nonmember');

        $this->actingAs($user);

        // Area in company B with no group membership for this user
        $foreignArea = Area::factory()->create(['company_id' => $companyB->id, 'status' => ActiveStatusEnum::ACTIVE]);

        $ticket = $this->makeTicketInArea($foreignArea, $user->id);

        $ids = TicketResource::getEloquentQuery()->pluck('id')->all();

        $this->assertNotContains($ticket->id, $ids,
            'Ticket in a foreign-company area with no group membership should be excluded.');
    }

    // ──────────────────────────────────────────────────────────────────
    // group_id Select options — query-level
    //
    // Reproduces the options closure from TicketResource form's group_id
    // Select. Given a selected area_id, groups visible to the user must
    // include groups they are a member of, even from a different company.
    // ──────────────────────────────────────────────────────────────────

    public function test_group_select_includes_membership_groups_in_foreign_company_area(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        [$employee, $user] = $this->makeEmployeeUserInCompany($companyA->id, 'gsel-member');
        $this->actingAs($user);

        $foreignArea = Area::factory()->create(['company_id' => $companyB->id, 'status' => ActiveStatusEnum::ACTIVE]);
        $memberGroup = $this->makeGroupInArea($foreignArea, $companyB);
        GroupMember::factory()->create(['group_id' => $memberGroup->id, 'employee_id' => $employee->id]);

        // Reproduce the group_id Select options closure.
        $areaId         = $foreignArea->id;
        $companyIds     = $user->scopedCompanyIds(); // [companyA->id]
        $memberGroupIds = Group::whereHas('members', fn ($q) => $q->where('employee_id', $employee->id))
            ->pluck('id')
            ->toArray();

        $options = Group::query()
            ->where('area_id', $areaId)
            ->where(function ($q) use ($companyIds, $memberGroupIds) {
                if (!empty($companyIds)) {
                    $q->whereIn('company_id', $companyIds);
                }
                if (!empty($memberGroupIds)) {
                    $q->orWhereIn('id', $memberGroupIds);
                }
            })
            ->where('status', ActiveStatusEnum::ACTIVE->value)
            ->pluck('name', 'id');

        $this->assertArrayHasKey($memberGroup->id, $options->toArray());
    }

    public function test_group_select_excludes_groups_in_foreign_area_without_membership(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        [$employee, $user] = $this->makeEmployeeUserInCompany($companyA->id, 'gsel-nonmember');
        $this->actingAs($user);

        $foreignArea  = Area::factory()->create(['company_id' => $companyB->id, 'status' => ActiveStatusEnum::ACTIVE]);
        $foreignGroup = $this->makeGroupInArea($foreignArea, $companyB);
        // No GroupMember for $employee

        $areaId         = $foreignArea->id;
        $companyIds     = $user->scopedCompanyIds(); // [companyA->id]
        $memberGroupIds = Group::whereHas('members', fn ($q) => $q->where('employee_id', $employee->id))
            ->pluck('id')
            ->toArray();

        $options = Group::query()
            ->where('area_id', $areaId)
            ->where(function ($q) use ($companyIds, $memberGroupIds) {
                if (!empty($companyIds)) {
                    $q->whereIn('company_id', $companyIds);
                }
                if (!empty($memberGroupIds)) {
                    $q->orWhereIn('id', $memberGroupIds);
                }
            })
            ->where('status', ActiveStatusEnum::ACTIVE->value)
            ->pluck('name', 'id');

        $this->assertArrayNotHasKey($foreignGroup->id, $options->toArray());
    }

    // ──────────────────────────────────────────────────────────────────
    // area_id filter options — query-level
    //
    // Reproduces the options closure from the area_id SelectFilter.
    // Must include own-company areas AND group-membership areas;
    // must exclude foreign areas with no membership.
    // ──────────────────────────────────────────────────────────────────

    public function test_area_filter_includes_own_company_and_membership_areas(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        [$employee, $user] = $this->makeEmployeeUserInCompany($companyA->id, 'af-member');
        $this->actingAs($user);

        $ownArea     = Area::factory()->create(['company_id' => $companyA->id, 'status' => ActiveStatusEnum::ACTIVE]);
        $foreignArea = Area::factory()->create(['company_id' => $companyB->id, 'status' => ActiveStatusEnum::ACTIVE]);
        $group       = $this->makeGroupInArea($foreignArea, $companyB);
        GroupMember::factory()->create(['group_id' => $group->id, 'employee_id' => $employee->id]);

        // Reproduce the area_id filter options closure.
        $companyIds   = $user->scopedCompanyIds(); // [companyA->id]
        $groupAreaIds = Group::whereHas('members', fn ($q) => $q->where('employee_id', $employee->id))
            ->pluck('area_id')
            ->toArray();

        $options = Area::query()
            ->where(function ($q) use ($companyIds, $groupAreaIds) {
                if (!empty($companyIds)) {
                    $q->whereIn('company_id', $companyIds);
                }
                if (!empty($groupAreaIds)) {
                    $q->orWhereIn('id', $groupAreaIds);
                }
            })
            ->where('status', ActiveStatusEnum::ACTIVE->value)
            ->pluck('name', 'id');

        $this->assertArrayHasKey($ownArea->id, $options->toArray(),
            'Own company area should be in filter options.');
        $this->assertArrayHasKey($foreignArea->id, $options->toArray(),
            'Group-membership area of foreign company should be in filter options.');
    }

    public function test_area_filter_excludes_foreign_areas_without_membership(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        [$employee, $user] = $this->makeEmployeeUserInCompany($companyA->id, 'af-nonmember');
        $this->actingAs($user);

        $foreignArea = Area::factory()->create(['company_id' => $companyB->id, 'status' => ActiveStatusEnum::ACTIVE]);
        // No group membership in foreignArea

        $companyIds   = $user->scopedCompanyIds(); // [companyA->id]
        $groupAreaIds = Group::whereHas('members', fn ($q) => $q->where('employee_id', $employee->id))
            ->pluck('area_id')
            ->toArray();

        $options = Area::query()
            ->where(function ($q) use ($companyIds, $groupAreaIds) {
                if (!empty($companyIds)) {
                    $q->whereIn('company_id', $companyIds);
                }
                if (!empty($groupAreaIds)) {
                    $q->orWhereIn('id', $groupAreaIds);
                }
            })
            ->where('status', ActiveStatusEnum::ACTIVE->value)
            ->pluck('name', 'id');

        $this->assertArrayNotHasKey($foreignArea->id, $options->toArray(),
            'Foreign area with no group membership should not appear in filter options.');
    }
}
