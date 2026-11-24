<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\EmployeeResource\RelationManagers\TeamTicketsRelationManager;
use App\Filament\Resources\TeamTicketResource;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SlaExemptionService;
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
 * One person, several HR records: HR opens a new record each time someone
 * moves company. Records stay as history; access and reports treat all
 * records with the same TC as one person.
 */
class PersonRecordsTest extends TestCase
{
    use RefreshDatabase;

    private TicketWorkflowService $workflow;

    private User $requester;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        Notification::fake();
        $this->seed(PermissionSeeder::class);
        $this->workflow = app(TicketWorkflowService::class);
        $this->requester = $this->account('talep@example.com');
        $this->supervisor = $this->account('amir@example.com');
        Employee::factory()->create(['email' => 'talep@example.com', 'status' => ActiveStatusEnum::ACTIVE]);
    }

    private function account(string $email): User
    {
        return User::factory()->create(['email' => $email, 'username' => 'u'.fake()->unique()->numerify('#######'), 'status' => UserStatusEnum::ACTIVE]);
    }

    /**
     * An old (inactive) record and a new (active) one of the same person.
     *
     * @return array{0: Employee, 1: Employee}
     */
    private function movedPerson(string $email, string $tc): array
    {
        $old = Employee::factory()->create(['email' => $email, 'tc_no' => $tc, 'status' => ActiveStatusEnum::INACTIVE, 'company_name' => 'Eski Proje']);
        $new = Employee::factory()->create(['email' => $email, 'tc_no' => $tc, 'status' => ActiveStatusEnum::ACTIVE, 'company_name' => 'Yeni Proje']);

        return [$old, $new];
    }

    private function team(Employee $supervisor, array $members = []): Team
    {
        $team = Team::factory()->create(['supervisor_employee_id' => $supervisor->id]);
        $team->members()->attach(collect($members)->pluck('id')->all());

        return $team;
    }

    public function test_records_with_the_same_tc_are_one_person(): void
    {
        [$old, $new] = $this->movedPerson('ali@example.com', '11111111110');
        $alone = Employee::factory()->create(['tc_no' => '']);

        $this->assertEqualsCanonicalizing([$old->id, $new->id], $new->personIds());
        $this->assertEqualsCanonicalizing([$old->id, $new->id], $old->personIds());
        $this->assertSame([$alone->id], $alone->personIds());
        $this->assertSame($new->id, $new->personRecords()->first()->id, 'active record first');
    }

    public function test_work_held_under_the_old_record_stays_with_the_person(): void
    {
        [$old, $new] = $this->movedPerson('tek@example.com', '22222222220');
        $tech = $this->account('tek@example.com');
        [, $amirNew] = $this->movedPerson('amir@example.com', '33333333330');
        $team = $this->team($amirNew, [$old]);

        $this->actingAs($this->requester);
        $ticket = $this->workflow->open($this->requester, $team, TaskPriorityEnum::High, 'Yazıcı arızası.');
        $ticket = $this->workflow->assign($ticket, $old, $this->supervisor);

        $this->actingAs($tech);
        $this->assertTrue(Ticket::query()->visibleInTeamWorkflow($tech)->whereKey($ticket->id)->exists());
        $this->assertTrue($this->workflow->isAssignee($ticket, $tech));

        $ticket = $this->workflow->resolve($this->workflow->startWork($ticket, $tech), $tech, 'Toner değişti.');
        $this->assertSame($old->id, $ticket->employee_id, 'history keeps the record it was done with');
    }

    public function test_supervisor_and_member_rights_follow_the_person(): void
    {
        [$amirOld] = $this->movedPerson('amir@example.com', '44444444440');
        [$memberOld] = $this->movedPerson('uye@example.com', '55555555550');
        $member = $this->account('uye@example.com');
        $team = $this->team($amirOld, [$memberOld]);

        $this->actingAs($this->requester);
        $ticket = $this->workflow->open($this->requester, $team, TaskPriorityEnum::High, 'Klima arızası.');

        $this->assertTrue($this->workflow->isSupervisor($ticket, $this->supervisor));
        $this->assertTrue($this->workflow->canWorkInTeam($team, $member->employee));

        $team->update(['allow_self_assign' => true]);
        $this->actingAs($member);
        $ticket = $this->workflow->selfAssign($ticket->fresh(), $member);
        $this->assertTrue($this->workflow->isAssignee($ticket, $member));
    }

    public function test_pickers_list_each_person_once_and_never_the_requester(): void
    {
        [, $new] = $this->movedPerson('cc@example.com', '66666666660');
        $secondActive = Employee::factory()->create(['email' => 'cc@example.com', 'tc_no' => '66666666660', 'status' => ActiveStatusEnum::ACTIVE]);
        [$ownOld] = $this->movedPerson('talep@example.com', '77777777770');
        $this->actingAs($this->requester);

        $candidates = TeamTicketResource::watcherCandidates()->pluck('id');

        $this->assertSame(1, $candidates->intersect([$new->id, $secondActive->id])->count(), 'one row per person');
        $this->assertTrue($candidates->contains($secondActive->id), 'newest active record');
        $this->assertFalse($candidates->contains($ownOld->id));
    }

    public function test_reports_and_personnel_page_join_the_records(): void
    {
        [$old, $new] = $this->movedPerson('tek@example.com', '88888888880');
        $tech = $this->account('tek@example.com');
        [, $amirNew] = $this->movedPerson('amir@example.com', '99999999990');
        $team = $this->team($amirNew, [$old, $new]);

        $this->actingAs($this->requester);
        $before = $this->workflow->assign($this->workflow->open($this->requester, $team, TaskPriorityEnum::High, 'Eski kayıtla tutulan iş.'), $old, $this->supervisor);
        $before = $this->workflow->hold($this->workflow->startWork($before, $tech), $tech, HoldReasonEnum::AwaitingParts);
        $after = $this->workflow->assign($this->workflow->open($this->requester, $team, TaskPriorityEnum::High, 'Yeni kayıtla tutulan iş.'), $new, $this->supervisor);

        $service = app(TeamPerformanceService::class);
        $this->assertSame(2, $service->currentWorkload($new)['active']);
        $this->assertSame(1, $service->holdFigures($new)['count']);

        $viewer = $this->account('izleyen@example.com');
        foreach (['view_any_employee', 'view_employee', 'view_all_employees'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $viewer->givePermissionTo(['view_any_employee', 'view_employee', 'view_all_employees', 'view_any_team::ticket', 'view_team::ticket', 'ticket.view.all']);
        $this->actingAs($viewer);

        Livewire::test(TeamTicketsRelationManager::class, ['ownerRecord' => $new, 'pageClass' => ViewEmployee::class])
            ->assertCanSeeTableRecords([$before, $after]);
        Livewire::test(ViewEmployee::class, ['record' => $new->getKey()])
            ->assertSee('Eski Proje')
            ->assertSee('Yeni Proje');
    }

    public function test_exemption_can_be_asked_for_a_breach_on_the_old_record(): void
    {
        [$old] = $this->movedPerson('tek@example.com', '12121212120');
        $tech = $this->account('tek@example.com');
        [, $amirNew] = $this->movedPerson('amir@example.com', '13131313130');
        $team = $this->team($amirNew, [$old]);

        $this->actingAs($this->requester);
        $ticket = $this->workflow->startWork($this->workflow->assign($this->workflow->open($this->requester, $team, TaskPriorityEnum::Urgent, 'Acil iş.'), $old, $this->supervisor), $tech);
        $this->travel(3)->hours();
        $ticket = $this->workflow->resolve($ticket, $tech, 'Geç bitti.');
        $cycle = $ticket->currentCycle()->first();

        $this->assertSame($old->id, $cycle->breach_employee_id);
        $this->assertTrue(app(SlaExemptionService::class)->canRequest($cycle, $tech));
    }
}
