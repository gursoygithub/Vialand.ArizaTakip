<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The personnel list and detail page show which company an employee belongs to.
 */
class EmployeeCompanyDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private Employee $employeeA;

    private Employee $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        // Shield's super_admin has no gate bypass here (define_via_gate=false),
        // so grant the resource permissions shield:generate would create.
        foreach (['view_any_employee', 'view_employee', 'view_all_employees'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['view_any_employee', 'view_employee', 'view_all_employees']);
        $this->actingAs($viewer);

        $this->companyA = Company::factory()->create(['name' => 'Alfa Eğlence A.Ş.']);
        $this->companyB = Company::factory()->create(['name' => 'Beta Teknik Ltd.']);

        $this->employeeA = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $this->employeeB = Employee::factory()->create(['company_id' => $this->companyB->id]);
    }

    public function test_employee_belongs_to_its_company(): void
    {
        $this->assertTrue($this->employeeA->company->is($this->companyA));
    }

    public function test_list_shows_the_company_column(): void
    {
        Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$this->employeeA, $this->employeeB])
            ->assertTableColumnExists('company.name')
            ->assertTableColumnStateSet('company.name', 'Alfa Eğlence A.Ş.', $this->employeeA)
            ->assertTableColumnStateSet('company.name', 'Beta Teknik Ltd.', $this->employeeB);
    }

    public function test_list_can_be_filtered_by_company(): void
    {
        Livewire::test(ListEmployees::class)
            ->filterTable('company_id', [$this->companyA->id])
            ->assertCanSeeTableRecords([$this->employeeA])
            ->assertCanNotSeeTableRecords([$this->employeeB]);
    }

    public function test_list_can_be_searched_by_company_name(): void
    {
        Livewire::test(ListEmployees::class)
            ->searchTable('Beta Teknik')
            ->assertCanSeeTableRecords([$this->employeeB])
            ->assertCanNotSeeTableRecords([$this->employeeA]);
    }

    public function test_detail_page_shows_the_company_name(): void
    {
        Livewire::test(ViewEmployee::class, ['record' => $this->employeeA->getKey()])
            ->assertSee('Alfa Eğlence A.Ş.');
    }

    public function test_employee_without_company_shows_a_placeholder(): void
    {
        $orphan = Employee::factory()->create(['company_id' => null]);

        Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$orphan])
            ->assertTableColumnStateNotSet('company.name', 'Alfa Eğlence A.Ş.', $orphan);
    }
}
