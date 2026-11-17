<?php

namespace Tests\Feature;

use App\Enums\TaskPriorityEnum;
use App\Filament\Resources\TeamResource;
use App\Filament\Resources\TeamResource\Pages\CreateTeam;
use App\Filament\Resources\TeamResource\Pages\EditTeam;
use App\Filament\Resources\TeamResource\Pages\ListTeams;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WorkCalendar;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\GrantsShieldPermissions;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use GrantsShieldPermissions, RefreshDatabase;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->userWithResourcePermissions(['team']));
        $this->type = TicketType::factory()->create(['name' => 'Elektrik']);
    }

    public function test_new_team_form_is_prefilled_with_one_sla_row_per_priority(): void
    {
        $undoRepeaterFake = Repeater::fake();

        $state = Livewire::test(CreateTeam::class)->get('data.slaPolicies');

        $undoRepeaterFake();

        $this->assertSame(
            collect(TaskPriorityEnum::cases())->map->value->all(),
            collect($state)->pluck('priority')->map(fn ($p) => (int) $p)->values()->all(),
        );
    }

    public function test_admin_creates_a_team_with_members_from_several_companies(): void
    {
        $alfa = Company::factory()->create(['name' => 'Alfa A.Ş.']);
        $beta = Company::factory()->create(['name' => 'Beta Ltd.']);
        $supervisor = Employee::factory()->create(['company_id' => $alfa->id]);
        $memberA = Employee::factory()->create(['company_id' => $alfa->id]);
        $memberB = Employee::factory()->create(['company_id' => $beta->id]);
        $calendar = WorkCalendar::factory()->create();

        Livewire::test(CreateTeam::class)
            ->fillForm([
                'ticket_type_id' => $this->type->id,
                'name' => 'Elektrik Ekibi',
                'supervisor_employee_id' => $supervisor->id,
                'work_calendar_id' => $calendar->id,
                'members' => [$memberA->id, $memberB->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $team = Team::where('name', 'Elektrik Ekibi')->sole();
        $this->assertTrue($team->supervisor->is($supervisor));
        $this->assertTrue($team->workCalendar->is($calendar));
        $this->assertEqualsCanonicalizing([$memberA->id, $memberB->id], $team->members->pluck('id')->all());
        $this->assertCount(4, $team->slaPolicies);
        $this->assertSame(
            Team::DEFAULT_SLA_MINUTES[TaskPriorityEnum::Urgent->value][1],
            $team->slaPolicyFor(TaskPriorityEnum::Urgent)->resolution_minutes,
        );
        $this->assertTrue($team->allow_self_assign);
    }

    public function test_type_and_supervisor_are_required(): void
    {
        Livewire::test(CreateTeam::class)
            ->fillForm(['name' => 'Eksik Ekip', 'ticket_type_id' => null, 'supervisor_employee_id' => null])
            ->call('create')
            ->assertHasFormErrors(['ticket_type_id' => 'required', 'supervisor_employee_id' => 'required']);
    }

    public function test_resolution_time_cannot_be_shorter_than_response_time(): void
    {
        $team = Team::factory()->create(['ticket_type_id' => $this->type->id]);
        $undoRepeaterFake = Repeater::fake();

        $rows = $team->slaPolicies->map(fn ($policy): array => [
            'priority' => $policy->priority->value,
            'response_minutes' => $policy->response_minutes,
            'resolution_minutes' => $policy->resolution_minutes,
            'success_threshold' => $policy->success_threshold,
        ])->values()->all();
        $rows[0]['response_minutes'] = 600;
        $rows[0]['resolution_minutes'] = 60;

        Livewire::test(EditTeam::class, ['record' => $team->getKey()])
            ->fillForm(['slaPolicies' => $rows])
            ->call('save')
            ->assertHasFormErrors(['slaPolicies.0.resolution_minutes' => 'gte']);

        $undoRepeaterFake();
    }

    public function test_sla_targets_can_be_edited(): void
    {
        $team = Team::factory()->create(['ticket_type_id' => $this->type->id]);
        $undoRepeaterFake = Repeater::fake();

        $rows = $team->slaPolicies->map(fn ($policy): array => [
            'priority' => $policy->priority->value,
            'response_minutes' => $policy->response_minutes,
            'resolution_minutes' => $policy->resolution_minutes,
            'success_threshold' => $policy->success_threshold,
        ])->values()->all();
        $rows[3]['response_minutes'] = 10;
        $rows[3]['resolution_minutes'] = 90;

        Livewire::test(EditTeam::class, ['record' => $team->getKey()])
            ->fillForm(['slaPolicies' => $rows])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $urgent = $team->fresh()->slaPolicyFor(TaskPriorityEnum::Urgent);
        $this->assertSame(10, $urgent->response_minutes);
        $this->assertSame(90, $urgent->resolution_minutes);
        $this->assertSame(4, $team->slaPolicies()->count());
    }

    public function test_list_can_be_filtered_by_type(): void
    {
        $electric = Team::factory()->create(['ticket_type_id' => $this->type->id]);
        $it = Team::factory()->create();

        Livewire::test(ListTeams::class)
            ->assertCanSeeTableRecords([$electric, $it])
            ->filterTable('ticket_type_id', $this->type->id)
            ->assertCanSeeTableRecords([$electric])
            ->assertCanNotSeeTableRecords([$it]);
    }

    public function test_team_with_tickets_cannot_be_deleted(): void
    {
        $used = Team::factory()->create(['ticket_type_id' => $this->type->id]);
        Ticket::factory()->create(['team_id' => $used->id, 'ticket_type_id' => $this->type->id]);
        $unused = Team::factory()->create(['ticket_type_id' => $this->type->id]);

        $this->assertFalse(TeamResource::canDelete($used));
        $this->assertTrue(TeamResource::canDelete($unused));
    }

    public function test_team_without_calendar_runs_around_the_clock(): void
    {
        $noCalendar = Team::factory()->create();
        $officeHours = Team::factory()->withCalendar()->create();
        $allDay = Team::factory()->withCalendar(WorkCalendar::factory()->aroundTheClock()->create())->create();

        $this->assertTrue($noCalendar->runsAroundTheClock());
        $this->assertFalse($officeHours->runsAroundTheClock());
        $this->assertTrue($allDay->runsAroundTheClock());
    }

    public function test_employee_label_shows_the_company(): void
    {
        $company = Company::factory()->create(['name' => 'Gama Turizm']);
        $employee = Employee::factory()->create(['name' => 'Ayşe Yılmaz', 'company_id' => $company->id]);

        $this->assertSame('Ayşe Yılmaz (Gama Turizm)', TeamResource::employeeLabel($employee));
    }

    public function test_employee_knows_its_teams(): void
    {
        $employee = Employee::factory()->create();
        $memberOf = Team::factory()->create();
        $memberOf->members()->attach($employee);
        $supervises = Team::factory()->create(['supervisor_employee_id' => $employee->id]);

        $this->assertTrue($employee->teams->first()->is($memberOf));
        $this->assertTrue($employee->supervisedTeams->first()->is($supervises));
    }

    public function test_user_without_permission_cannot_open_teams(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(TeamResource::getUrl('index'))->assertForbidden();
    }
}
