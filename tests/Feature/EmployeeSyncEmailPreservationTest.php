<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeSyncEmailPreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /**
     * Call the protected processBatch() method via reflection.
     * Catches the MySQL-specific INNER JOIN syntax error on SQLite (step 4).
     */
    private function callProcessBatch(EmployeeService $service, array $buffer): void
    {
        $method = new \ReflectionMethod(EmployeeService::class, 'processBatch');
        $method->setAccessible(true);
        try {
            $method->invoke($service, $buffer);
        } catch (\Illuminate\Database\QueryException $e) {
            // Step 4 uses MySQL INNER JOIN UPDATE syntax; expected to fail on SQLite.
            if (!str_contains($e->getMessage(), 'syntax error')) {
                throw $e;
            }
        }
    }

    /**
     * Build a service instance without hitting the real SQL Server connection.
     */
    private function makeService(): EmployeeService
    {
        $service = (new \ReflectionClass(EmployeeService::class))
            ->newInstanceWithoutConstructor();

        return $service;
    }

    public function test_blank_email_does_not_overwrite_existing(): void
    {
        $employee = Employee::factory()->create([
            'employee_id' => 'EMP001',
            'email'       => 'test@x.com',
        ]);

        $service = $this->makeService();

        $this->callProcessBatch($service, [[
            'employee_id'  => 'EMP001',
            'name'         => 'Updated Name',
            'tc_no'        => $employee->tc_no,
            'email'        => '',
            'phone'        => '555-1234',
            'status'       => 1,
            'title'        => 'Engineer',
            'profession'   => 'IT',
            'company_name' => '',
            'created_by'   => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]]);

        $fresh = $employee->fresh();
        $this->assertEquals('test@x.com', $fresh->email);
        $this->assertEquals('Updated Name', $fresh->name);
    }

    public function test_non_blank_email_updates_existing(): void
    {
        $employee = Employee::factory()->create([
            'employee_id' => 'EMP002',
            'email'       => 'old@x.com',
        ]);

        $service = $this->makeService();

        $this->callProcessBatch($service, [[
            'employee_id'  => 'EMP002',
            'name'         => $employee->name,
            'tc_no'        => $employee->tc_no,
            'email'        => 'new@x.com',
            'phone'        => $employee->phone,
            'status'       => 1,
            'title'        => $employee->title,
            'profession'   => $employee->profession,
            'company_name' => '',
            'created_by'   => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]]);

        $this->assertEquals('new@x.com', $employee->fresh()->email);
    }
}
