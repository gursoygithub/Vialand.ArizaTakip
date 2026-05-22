<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmployeeSyncRelinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_orphan_user_gets_linked_when_employee_email_matches(): void
    {
        $employee = Employee::factory()->create([
            'employee_id' => 'EMP123',
            'email'       => 'match@x.com',
        ]);

        $user = User::factory()->create([
            'email'       => 'match@x.com',
            'employee_id' => null,
        ]);

        $this->assertNull($user->fresh()->employee_id);

        // Build EmployeeService without constructor (avoids sqlsrv2 connection).
        $service = (new \ReflectionClass(EmployeeService::class))
            ->newInstanceWithoutConstructor();

        // Mock the SQL Server connection to return an empty cursor
        // so getEmployees() runs through to the relink step.
        $mockBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
        $mockBuilder->shouldReceive('where')->andReturnSelf();
        $mockBuilder->shouldReceive('whereNotNull')->andReturnSelf();
        $mockBuilder->shouldReceive('orWhere')->andReturnSelf();
        $mockBuilder->shouldReceive('orderBy')->andReturnSelf();
        $mockBuilder->shouldReceive('cursor')->andReturn(collect([]));

        $mockConnection = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
        $mockConnection->shouldReceive('table')
            ->with('_TGRY_PERSONEL')
            ->andReturn($mockBuilder);

        $prop = new \ReflectionProperty(EmployeeService::class, 'sqlsrvConnection');
        $prop->setAccessible(true);
        $prop->setValue($service, $mockConnection);

        $service->getEmployees();

        $this->assertEquals('EMP123', $user->fresh()->employee_id);
    }
}
