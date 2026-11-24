<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Filament\Resources\TeamTicketResource;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * People missing from the HR sync (they left) are switched off, but a short
 * or empty HR answer never switches anyone off.
 */
class EmployeeSyncDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        // No constructor: it would open the HR (sqlsrv2) connection.
        $this->service = (new \ReflectionClass(EmployeeService::class))->newInstanceWithoutConstructor();
    }

    /**
     * @return array<int, Employee>
     */
    private function activePeople(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $i): Employee => Employee::factory()->create(['employee_id' => 'HR'.$i, 'status' => ActiveStatusEnum::ACTIVE]))
            ->all();
    }

    public function test_people_missing_from_hr_are_switched_off(): void
    {
        $people = $this->activePeople(4);

        $count = $this->service->deactivateMissing(['HR1', 'HR2', 'HR3']);

        $this->assertSame(1, $count);
        $this->assertSame(ActiveStatusEnum::INACTIVE, $people[3]->fresh()->status);
        $this->assertSame(ActiveStatusEnum::ACTIVE, $people[0]->fresh()->status);
    }

    public function test_an_empty_or_short_hr_answer_switches_nobody_off(): void
    {
        $people = $this->activePeople(4);

        $this->assertSame(0, $this->service->deactivateMissing([]));
        $this->assertSame(0, $this->service->deactivateMissing(['HR1']));

        foreach ($people as $employee) {
            $this->assertSame(ActiveStatusEnum::ACTIVE, $employee->fresh()->status);
        }
    }

    public function test_someone_who_left_is_no_longer_offered_as_cc(): void
    {
        $people = collect(range(1, 3))->map(fn (int $i): Employee => Employee::factory()->create([
            'employee_id' => 'HR'.$i, 'status' => ActiveStatusEnum::ACTIVE, 'email' => "kisi{$i}@example.com",
        ]));

        $this->service->deactivateMissing(['HR1', 'HR2']);

        $candidates = TeamTicketResource::watcherCandidates()->pluck('id');
        $this->assertTrue($candidates->contains($people[0]->id));
        $this->assertFalse($candidates->contains($people[2]->id));
    }

    public function test_team_links_move_to_the_persons_new_record(): void
    {
        $old = Employee::factory()->create(['email' => 'anil@example.com', 'status' => ActiveStatusEnum::INACTIVE]);
        $new = Employee::factory()->create(['email' => 'anil@example.com', 'status' => ActiveStatusEnum::ACTIVE]);
        $leaver = Employee::factory()->create(['email' => 'ulas@example.com', 'status' => ActiveStatusEnum::INACTIVE]);
        $led = Team::factory()->create(['supervisor_employee_id' => $old->id]);
        $joined = Team::factory()->create();
        $joined->members()->attach([$old->id, $leaver->id]);
        $both = Team::factory()->create();
        $both->members()->attach([$old->id, $new->id]);

        $this->service->moveTeamLinksToActiveRecords();

        $this->assertSame($new->id, $led->fresh()->supervisor_employee_id);
        $this->assertEqualsCanonicalizing([$new->id, $leaver->id], $joined->members()->pluck('employees.id')->all());
        $this->assertSame([$new->id], $both->members()->pluck('employees.id')->all());
    }

    public function test_account_matches_the_active_record_of_the_same_address(): void
    {
        Employee::factory()->create(['email' => 'anil@example.com', 'status' => ActiveStatusEnum::ACTIVE]);
        Employee::factory()->create(['email' => 'anil@example.com', 'status' => ActiveStatusEnum::INACTIVE]);
        $user = User::factory()->create(['email' => 'anil@example.com']);

        $this->assertSame(ActiveStatusEnum::ACTIVE, $user->employee->status);
        $this->assertSame(ActiveStatusEnum::ACTIVE, $user->fresh()->load('employee')->employee->status);
    }
}
