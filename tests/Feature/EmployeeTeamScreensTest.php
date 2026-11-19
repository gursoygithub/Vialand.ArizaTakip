<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\EmployeeResource\RelationManagers\TeamMembershipsRelationManager;
use App\Filament\Resources\EmployeeResource\RelationManagers\TeamTicketsRelationManager;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TeamPerformanceService;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * With the legacy switch off, the personnel screens (list, detail, relation
 * managers) show the team model: teams, requests held and the person's SLA
 * figures by the report's rules. The team runs 7/24; Urgent = 120 min.
 */
class EmployeeTeamScreensTest extends TestCase
{
    use RefreshDatabase;

    private TicketWorkflowService $workflow;

    private Team $team;

    private User $requester;

    private User $supervisor;

    private User $tech1;

    private User $tech2;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00'));
        Notification::fake();
        $this->seed(PermissionSeeder::class);

        $this->requester = $this->person()[1];
        [$supervisorEmployee, $this->supervisor] = $this->person();
        [$tech1Employee, $this->tech1] = $this->person();
        [$tech2Employee, $this->tech2] = $this->person();

        $this->viewer = $this->person()[1];
        foreach (['view_any_employee', 'view_employee', 'view_all_employees', 'view_any_team::ticket', 'view_team::ticket', 'view_any_team', 'view_team'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->viewer->givePermissionTo(['view_any_employee', 'view_employee', 'view_all_employees', 'view_any_team::ticket', 'view_team::ticket', 'view_any_team', 'view_team', 'ticket.view.all']);

        $this->actingAs($this->requester);
        $this->team = Team::factory()->create(['name' => 'Teknik', 'supervisor_employee_id' => $supervisorEmployee->id]);
        $this->team->members()->attach([$tech1Employee->id, $tech2Employee->id]);
        $this->workflow = app(TicketWorkflowService::class);
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    private function person(): array
    {
        $email = fake()->unique()->safeEmail();
        $user = User::factory()->create(['email' => $email, 'username' => 'u'.fake()->unique()->numerify('#######'), 'status' => UserStatusEnum::ACTIVE]);

        return [Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE]), $user];
    }

    private function open(TaskPriorityEnum $priority = TaskPriorityEnum::Urgent): Ticket
    {
        return $this->workflow->open($this->requester, $this->team, $priority, 'Arıza açıklaması.');
    }

    private function startedBy(User $tech, ?Ticket $ticket = null): Ticket
    {
        $ticket = $this->workflow->assign($ticket ?? $this->open(), $tech->employee, $this->supervisor);

        return $this->workflow->startWork($ticket, $tech);
    }

    private function resolveAndConfirm(Ticket $ticket, User $tech, ?int $score = null): Ticket
    {
        return $this->workflow->confirm($this->workflow->resolve($ticket, $tech), $this->requester, $score);
    }

    /**
     * tech1: one on time (rated 4), one late (personal). tech2: late after a
     * handover from tech1 with < 25% left — the team's breach, not tech2's.
     */
    private function history(): void
    {
        $this->resolveAndConfirm($this->startedBy($this->tech1), $this->tech1, 4);

        $late = $this->startedBy($this->tech1);
        $this->travel(150)->minutes();
        $this->resolveAndConfirm($late, $this->tech1);

        $handover = $this->startedBy($this->tech1);
        $this->travel(100)->minutes();
        $handover = $this->workflow->startWork($this->workflow->assign($handover, $this->tech2->employee, $this->supervisor), $this->tech2);
        $this->travel(60)->minutes();
        $this->resolveAndConfirm($handover, $this->tech2);
    }

    public function test_person_report_uses_the_report_rules(): void
    {
        $this->history();
        $service = app(TeamPerformanceService::class);

        $tech1 = $service->personReport($this->tech1->employee);
        $this->assertSame(2, $tech1['summary']['total'], 'the handed-over cycle is not on tech1');
        $this->assertSame(1, $tech1['summary']['personal_breaches']);
        $this->assertSame(50.0, $tech1['summary']['compliance']);
        $this->assertSame(80.0, $tech1['summary']['target']);
        $this->assertSame(4.0, $tech1['summary']['satisfaction']);
        $this->assertSame(1, $tech1['summary']['rated']);
        $this->assertSame('Teknik', $tech1['teams'][0]['name']);
        $this->assertSame(TaskPriorityEnum::Urgent, $tech1['priorities'][0]['priority']);
        $this->assertSame(50.0, $tech1['priorities'][0]['compliance']);

        $tech2 = $service->personReport($this->tech2->employee);
        $this->assertSame(1, $tech2['summary']['total']);
        $this->assertSame(0, $tech2['summary']['personal_breaches'], 'handover breach stays on the team');
        $this->assertSame(100.0, $tech2['summary']['compliance']);

        $this->assertEquals(
            collect(app(TeamPerformanceService::class)->report(now()->subYear(), now(), $this->supervisor)['people'])
                ->firstWhere('name', $this->tech1->employee->name)['compliance'],
            $tech1['summary']['compliance'],
            'same figure as the team report',
        );
    }

    public function test_last_30_days_only_counts_recent_cycles(): void
    {
        $this->history();
        $this->travel(40)->days();

        $report = app(TeamPerformanceService::class)->personReport($this->tech1->employee);

        $this->assertSame(0, $report['last30']['total']);
        $this->assertNull($report['last30']['compliance']);
        $this->assertSame(2, $report['summary']['total']);
    }

    public function test_workload_counts_open_requests_held_and_overdue(): void
    {
        $this->startedBy($this->tech1);
        $overdue = $this->startedBy($this->tech1);
        $this->resolveAndConfirm($this->startedBy($this->tech1), $this->tech1);
        $this->travel(130)->minutes();
        $this->workflow->flagResolutionBreach($overdue->currentCycle);

        $this->assertSame(['active' => 2, 'breached' => 1], app(TeamPerformanceService::class)->currentWorkload($this->tech1->employee));
        $this->assertSame(['active' => 0, 'breached' => 0], app(TeamPerformanceService::class)->currentWorkload($this->tech2->employee));
    }

    public function test_list_shows_teams_and_requests_on_them(): void
    {
        $this->startedBy($this->tech1);
        $other = $this->person()[0];
        $this->actingAs($this->viewer);

        $list = Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$this->tech1->employee, $this->supervisor->employee])
            ->assertTableColumnFormattedStateSet('team_count', '1 ekip', $this->tech1->employee)
            ->assertTableColumnFormattedStateSet('team_count', '1 ekip · 1 amir', $this->supervisor->employee)
            ->assertTableColumnDoesNotExist('performance_score');

        $rows = $list->instance()->getTableRecords()->keyBy('id');
        $this->assertSame(1, (int) $rows[$this->tech1->employee->id]->held_requests_count);
        $this->assertSame(0, (int) $rows[$this->tech1->employee->id]->overdue_requests_count);
        $this->assertSame(0, (int) $rows[$this->tech2->employee->id]->held_requests_count);

        $list
            ->filterTable('team', [$this->team->id])
            ->assertCanSeeTableRecords([$this->tech1->employee, $this->supervisor->employee])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_team_names_are_listed_with_supervised_ones_marked(): void
    {
        Team::factory()->create(['name' => 'Elektrik', 'supervisor_employee_id' => $this->tech1->employee->id]);
        $employee = $this->tech1->employee->fresh(['teams', 'supervisedTeams']);

        $this->assertSame(['Elektrik (Amir)', 'Teknik'], EmployeeResource::teamLabels($employee));
        $this->assertSame('2 ekip · 1 amir', EmployeeResource::teamCountLabel(2, $employee));
    }

    public function test_detail_page_shows_team_figures_not_legacy_units(): void
    {
        $this->history();
        $this->actingAs($this->viewer);

        Livewire::test(ViewEmployee::class, ['record' => $this->tech1->employee->getKey()])
            ->assertOk()
            ->assertSee('Teknik')
            ->assertSee('%50,0')
            ->assertSee(__('ui.employee_team_breakdown'))
            ->assertSee(__('ui.report_personal_breaches'))
            ->assertDontSee(__('ui.employee_unit_sla'));
    }

    public function test_detail_page_without_history_shows_an_empty_summary(): void
    {
        $this->actingAs($this->viewer);

        Livewire::test(ViewEmployee::class, ['record' => $this->tech2->employee->getKey()])
            ->assertOk()
            ->assertSee(__('ui.employee_summary_empty'))
            ->assertDontSee(__('ui.employee_team_breakdown'));
    }

    public function test_requests_relation_lists_held_and_previously_held_requests(): void
    {
        $handover = $this->startedBy($this->tech1);
        $handover = $this->workflow->startWork($this->workflow->assign($handover, $this->tech2->employee, $this->supervisor), $this->tech2);
        $held = $this->startedBy($this->tech1);
        $notTheirs = $this->startedBy($this->tech2);
        $this->actingAs($this->viewer);

        Livewire::test(TeamTicketsRelationManager::class, ['ownerRecord' => $this->tech1->employee, 'pageClass' => ViewEmployee::class])
            ->assertCanSeeTableRecords([$handover, $held])
            ->assertCanNotSeeTableRecords([$notTheirs])
            ->assertTableColumnStateSet('holder', __('ui.employee_request_on_them'), $held)
            ->assertTableColumnStateSet('holder', $this->tech2->employee->name, $handover)
            ->filterTable('involvement', 'holder')
            ->assertCanSeeTableRecords([$held])
            ->assertCanNotSeeTableRecords([$handover]);
    }

    public function test_requests_relation_lists_supervised_requested_and_cc_requests(): void
    {
        $inTheirTeam = $this->workflow->open($this->requester, $this->team, TaskPriorityEnum::Urgent, 'Klima arızası.');
        $otherTeam = Team::factory()->create();
        $this->actingAs($this->supervisor);
        $requested = $this->workflow->open($this->supervisor, $otherTeam, TaskPriorityEnum::Urgent, 'Yazıcı arızası.');
        $this->actingAs($this->requester);
        $informed = $this->workflow->open($this->requester, $otherTeam, TaskPriorityEnum::Urgent, 'Kapı arızası.', [], [], [$this->supervisor->employee->id]);
        $unrelated = $this->workflow->open($this->requester, $otherTeam, TaskPriorityEnum::Urgent, 'Lamba arızası.');
        $this->actingAs($this->viewer);

        Livewire::test(TeamTicketsRelationManager::class, ['ownerRecord' => $this->supervisor->employee, 'pageClass' => ViewEmployee::class])
            ->assertCanSeeTableRecords([$inTheirTeam, $requested, $informed])
            ->assertCanNotSeeTableRecords([$unrelated])
            ->assertTableColumnStateSet('involvement', [__('ui.employee_involvement_supervisor')], $inTheirTeam)
            ->assertTableColumnStateSet('involvement', [__('ui.employee_involvement_requester')], $requested)
            ->assertTableColumnStateSet('involvement', [__('ui.employee_involvement_watcher')], $informed)
            ->filterTable('involvement', 'supervisor')
            ->assertCanSeeTableRecords([$inTheirTeam])
            ->assertCanNotSeeTableRecords([$requested, $informed]);
    }

    public function test_requests_relation_respects_request_visibility(): void
    {
        $this->startedBy($this->tech1);
        [, $outsider] = $this->person();
        $outsider->givePermissionTo(['view_any_team::ticket']);
        $this->actingAs($outsider);

        Livewire::test(TeamTicketsRelationManager::class, ['ownerRecord' => $this->tech1->employee, 'pageClass' => ViewEmployee::class])
            ->assertCountTableRecords(0);
    }

    public function test_team_memberships_list_member_and_supervised_teams(): void
    {
        $supervises = Team::factory()->create(['name' => 'Elektrik', 'supervisor_employee_id' => $this->tech1->employee->id]);
        $both = Team::factory()->create(['name' => 'Bakım', 'supervisor_employee_id' => $this->tech1->employee->id]);
        $both->members()->attach($this->tech1->employee);
        $unrelated = Team::factory()->create();
        $this->actingAs($this->viewer);

        Livewire::test(TeamMembershipsRelationManager::class, ['ownerRecord' => $this->tech1->employee, 'pageClass' => ViewEmployee::class])
            ->assertCanSeeTableRecords([$this->team, $supervises, $both])
            ->assertCanNotSeeTableRecords([$unrelated])
            ->assertTableColumnStateSet('role', __('ui.employee_team_role_member'), $this->team)
            ->assertTableColumnStateSet('role', __('ui.supervisor'), $supervises)
            ->assertTableColumnStateSet('role', __('ui.employee_team_role_both'), $both);
    }

    public function test_relation_managers_are_requests_and_teams(): void
    {
        $this->assertSame([TeamTicketsRelationManager::class, TeamMembershipsRelationManager::class], EmployeeResource::getRelations());
    }
}
