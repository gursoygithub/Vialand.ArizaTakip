<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Filament\Resources\EmployeeResource\RelationManagers\GroupMembershipsRelationManager;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroupMembershipsRMTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('super_admin');
        $this->actingAs($this->user);

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create([
            'company_id' => $this->company->id,
            'status' => ActiveStatusEnum::ACTIVE,
        ]);
    }

    private function makeGroup(?Employee $manager = null): Group
    {
        return Group::factory()->create([
            'company_id' => $this->company->id,
            'area_id' => Area::factory()->create(['company_id' => $this->company->id])->id,
            'unit_id' => Unit::factory()->create()->id,
            'employee_id' => $manager?->id ?? Employee::factory()->create(['company_id' => $this->company->id])->id,
            'status' => ActiveStatusEnum::ACTIVE,
        ]);
    }

    public function test_member_only_group_appears_with_uye_role(): void
    {
        $otherManager = Employee::factory()->create(['company_id' => $this->company->id]);
        $group = $this->makeGroup($otherManager);

        GroupMember::factory()->create([
            'group_id' => $group->id,
            'employee_id' => $this->employee->id,
        ]);

        Livewire::test(GroupMembershipsRelationManager::class, [
            'ownerRecord' => $this->employee,
            'pageClass' => \App\Filament\Resources\EmployeeResource\Pages\ViewEmployee::class,
        ])
            ->assertCanSeeTableRecords([$group])
            ->assertTableColumnStateSet('role_label', __('ui.group_role_member'), $group);
    }

    public function test_manager_only_group_appears_with_yonetici_role(): void
    {
        $group = $this->makeGroup($this->employee);
        // No group_members row for this employee

        Livewire::test(GroupMembershipsRelationManager::class, [
            'ownerRecord' => $this->employee,
            'pageClass' => \App\Filament\Resources\EmployeeResource\Pages\ViewEmployee::class,
        ])
            ->assertCanSeeTableRecords([$group])
            ->assertTableColumnStateSet('role_label', __('ui.group_role_manager'), $group);
    }

    public function test_member_and_manager_group_appears_with_combined_role(): void
    {
        $group = $this->makeGroup($this->employee);

        GroupMember::factory()->create([
            'group_id' => $group->id,
            'employee_id' => $this->employee->id,
        ]);

        Livewire::test(GroupMembershipsRelationManager::class, [
            'ownerRecord' => $this->employee,
            'pageClass' => \App\Filament\Resources\EmployeeResource\Pages\ViewEmployee::class,
        ])
            ->assertCanSeeTableRecords([$group])
            ->assertTableColumnStateSet('role_label', __('ui.group_role_member_manager'), $group);
    }

    public function test_unrelated_group_does_not_appear(): void
    {
        $unrelatedGroup = $this->makeGroup();
        // Employee is neither manager nor member of this group

        Livewire::test(GroupMembershipsRelationManager::class, [
            'ownerRecord' => $this->employee,
            'pageClass' => \App\Filament\Resources\EmployeeResource\Pages\ViewEmployee::class,
        ])
            ->assertCanNotSeeTableRecords([$unrelatedGroup]);
    }
}
