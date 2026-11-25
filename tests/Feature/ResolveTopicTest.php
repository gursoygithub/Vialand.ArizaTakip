<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\LocationRequirementEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\UserStatusEnum;
use App\Exceptions\TicketWorkflowException;
use App\Filament\Resources\TeamResource\Pages\EditTeam;
use App\Filament\Resources\TeamTicketResource;
use App\Filament\Resources\TeamTicketResource\Pages\CreateTeamTicket;
use App\Filament\Resources\TeamTicketResource\Pages\ListTeamTickets;
use App\Filament\Resources\TeamTicketResource\Pages\ViewTeamTicket;
use App\Models\Area;
use App\Models\Employee;
use App\Models\SubArea;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketWorkflowService;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Concerns\GrantsShieldPermissions;
use Tests\TestCase;

/**
 * Resolving picks one of the team's topics and, when the request type asks,
 * the location. Descriptions are written in a rich editor.
 */
class ResolveTopicTest extends TestCase
{
    use GrantsShieldPermissions, RefreshDatabase;

    private TicketWorkflowService $workflow;

    private TicketType $type;

    private Team $team;

    private User $requester;

    private User $supervisor;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        Notification::fake();
        $this->seed(PermissionSeeder::class);

        $this->requester = $this->person()[1];
        [$supervisorEmployee, $this->supervisor] = $this->person();
        [$techEmployee, $this->tech] = $this->person();
        $this->actingAs($this->requester);

        $this->type = TicketType::factory()->create(['location_requirement' => LocationRequirementEnum::Hidden, 'form_fields' => []]);
        $this->team = Team::factory()->create(['ticket_type_id' => $this->type->id, 'supervisor_employee_id' => $supervisorEmployee->id]);
        $this->team->members()->attach($techEmployee->id);
        $this->workflow = app(TicketWorkflowService::class);
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    private function person(): array
    {
        $email = fake()->unique()->safeEmail();
        $user = User::factory()->create(['email' => $email, 'username' => 'u'.fake()->unique()->numerify('#######'), 'status' => UserStatusEnum::ACTIVE]);
        $user->givePermissionTo(['view_any_team::ticket', 'view_team::ticket', 'create_team::ticket']);

        return [Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE]), $user];
    }

    private function started(string $description = 'Yazıcı çıktı vermiyor.'): Ticket
    {
        $ticket = $this->workflow->open($this->requester, $this->team, TaskPriorityEnum::High, $description);
        $ticket = $this->workflow->assign($ticket, $this->tech->employee, $this->supervisor);

        return $this->workflow->startWork($ticket, $this->tech);
    }

    private function assertRefused(callable $step): void
    {
        try {
            $step();
            $this->fail('The step should have been refused.');
        } catch (TicketWorkflowException) {
            $this->addToAssertionCount(1);
        }
    }

    // ── Topics ──────────────────────────────────────────────────────────

    public function test_a_team_without_topics_resolves_as_before(): void
    {
        $ticket = $this->workflow->resolve($this->started(), $this->tech, 'Tamam.');

        $this->assertSame(TaskStatusEnum::RESOLVED, $ticket->status);
        $this->assertNull($ticket->topic_id);
    }

    public function test_topic_is_required_and_must_be_an_active_one_of_the_team(): void
    {
        $topic = $this->team->topics()->create(['name' => 'Yazıcı']);
        $passive = $this->team->topics()->create(['name' => 'Faks', 'is_active' => false]);
        $foreign = Team::factory()->create()->topics()->create(['name' => 'Klima']);
        $ticket = $this->started();

        foreach ([null, $passive->id, $foreign->id, 999999] as $wrong) {
            $this->assertRefused(fn () => $this->workflow->resolve($ticket, $this->tech, 'Tamam.', $wrong));
        }
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, $ticket->fresh()->status);

        $this->assertSame($topic->id, $this->workflow->resolve($ticket, $this->tech, 'Tamam.', $topic->id)->topic_id);
    }

    public function test_resolve_form_asks_for_the_topic(): void
    {
        $topic = $this->team->topics()->create(['name' => 'Yazıcı']);
        $ticket = $this->started();
        $this->actingAs($this->tech);

        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->callAction('resolve', data: ['note' => 'Tamam.'])
            ->assertHasActionErrors(['topic_id' => 'required'])
            ->callAction('resolve', data: ['note' => 'Tamam.', 'topic_id' => $topic->id])
            ->assertHasNoActionErrors();

        $this->assertSame(TaskStatusEnum::RESOLVED, $ticket->fresh()->status);
        $this->get(TeamTicketResource::getUrl('view', ['record' => $ticket]))->assertSee('Yazıcı');
    }

    public function test_removed_topic_stays_on_past_requests(): void
    {
        $topic = $this->team->topics()->create(['name' => 'Yazıcı']);
        $ticket = $this->workflow->resolve($this->started(), $this->tech, 'Tamam.', $topic->id);

        $topic->delete();

        $this->assertSame('Yazıcı', $ticket->fresh()->topic->name);
        $this->assertFalse($this->team->topics()->whereKey($topic->id)->exists());
    }

    public function test_transfer_drops_the_old_teams_topic(): void
    {
        $topic = $this->team->topics()->create(['name' => 'Yazıcı']);
        $ticket = $this->started();
        $ticket->update(['topic_id' => null]);
        $ticket->forceFill(['topic_id' => $topic->id])->save();

        $ticket = $this->workflow->transfer($ticket, Team::factory()->create(), $this->supervisor, 'Onların işi.');

        $this->assertNull($ticket->topic_id);
    }

    public function test_topics_are_managed_on_the_team_form(): void
    {
        $this->actingAs($this->userWithResourcePermissions(['team']));
        $old = $this->team->topics()->create(['name' => 'Faks']);
        $undo = Repeater::fake();

        Livewire::test(EditTeam::class, ['record' => $this->team->getKey()])
            ->fillForm(['topics' => [['name' => 'Ağ', 'is_active' => true], ['name' => 'Yazıcı', 'is_active' => false]]])
            ->call('save')
            ->assertHasNoFormErrors();
        $undo();

        $this->assertSame(['Ağ', 'Yazıcı'], $this->team->topics()->pluck('name')->all());
        $this->assertSame(['Ağ'], $this->team->topics()->active()->pluck('name')->all());
        $this->assertSoftDeleted($old);
    }

    // ── Location ────────────────────────────────────────────────────────

    public function test_location_is_required_when_the_type_says_so(): void
    {
        $this->type->update(['resolve_location_requirement' => LocationRequirementEnum::Required]);
        $area = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $foreign = SubArea::factory()->create(['area_id' => Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE])->id]);
        $bare = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $ticket = $this->started();

        foreach ([[], ['area_id' => $area->id], ['area_id' => $area->id, 'sub_area_id' => $foreign->id]] as $wrong) {
            $this->assertRefused(fn () => $this->workflow->resolve($ticket, $this->tech, 'Tamam.', null, $wrong));
        }

        $resolved = $this->workflow->resolve($ticket, $this->tech, 'Tamam.', null, ['area_id' => $area->id, 'sub_area_id' => $subArea->id]);
        $this->assertSame([$area->id, $subArea->id], [$resolved->area_id, $resolved->sub_area_id]);

        $other = $this->workflow->resolve($this->started(), $this->tech, 'Tamam.', null, ['area_id' => $bare->id]);
        $this->assertSame($bare->id, $other->area_id, 'an area without sub areas is enough');
    }

    public function test_location_form_shows_only_when_asked_and_keeps_what_was_entered(): void
    {
        $area = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $ticket = $this->started();
        $ticket->update(['area_id' => $area->id]);
        $this->actingAs($this->tech);

        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->mountAction('resolve')
            ->assertDontSeeHtml('mountedActionsData.0.sub_area_id');

        $this->type->update(['resolve_location_requirement' => LocationRequirementEnum::Required]);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->mountAction('resolve')
            ->assertSeeHtml('mountedActionsData.0.sub_area_id')
            ->assertActionDataSet(['area_id' => $area->id])
            ->setActionData(['note' => 'Tamam.'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($area->id, $ticket->fresh()->area_id);
    }

    // ── Description ─────────────────────────────────────────────────────

    public function test_rich_text_keeps_formatting_and_drops_anything_active(): void
    {
        $clean = RichText::clean('<p onclick="x()">Merhaba <strong>dünya</strong></p><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">a</a><a href="https://x.tr">b</a><p style="position:fixed">c</p>');

        $this->assertStringContainsString('<strong>dünya</strong>', $clean);
        $this->assertStringContainsString('href="https://x.tr"', $clean);
        foreach (['script', 'onclick', 'onerror', '<img', 'javascript', 'style='] as $bad) {
            $this->assertStringNotContainsString($bad, $clean);
        }
        $this->assertStringNotContainsString('<b>', (string) RichText::render('a <b>x</b>'));
        $this->assertStringNotContainsString('<script', (string) RichText::render('<p>x</p><script>alert(1)</script>'));
    }

    public function test_request_is_opened_with_formatted_text_and_empty_markup_is_refused(): void
    {
        $fill = ['ticket_type_id' => $this->type->id, 'team_id' => $this->team->id, 'priority' => TaskPriorityEnum::Medium->value];

        Livewire::test(CreateTeamTicket::class)
            ->fillForm($fill + ['description' => '<p><br></p><p>&nbsp; kısa</p>'])
            ->call('create')
            ->assertHasFormErrors(['description']);

        Livewire::test(CreateTeamTicket::class)
            ->fillForm($fill + ['description' => '<p>Yazıcı <strong>çalışmıyor</strong></p><script>alert(1)</script>'])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Ticket::query()->latest('id')->first();
        $this->assertSame('<p>Yazıcı <strong>çalışmıyor</strong></p>', $ticket->description);
        $this->get(TeamTicketResource::getUrl('view', ['record' => $ticket]))->assertSee('<strong>çalışmıyor</strong>', false);
    }

    // ── List ────────────────────────────────────────────────────────────

    public function test_list_filters_by_assignee_across_records_and_by_topic(): void
    {
        $topic = $this->team->topics()->create(['name' => 'Yazıcı']);
        $mine = $this->workflow->resolve($this->started(), $this->tech, 'Tamam.', $topic->id);
        $pool = $this->workflow->open($this->requester, $this->team, TaskPriorityEnum::Low, 'Havuzdaki iş burada.');
        $newRecord = Employee::factory()->create(['tc_no' => $this->tech->employee->tc_no, 'name' => $this->tech->employee->name, 'status' => ActiveStatusEnum::ACTIVE]);
        $moved = $this->workflow->open($this->requester, $this->team, TaskPriorityEnum::Low, 'Yeni kayıttaki iş.');
        $moved->update(['employee_id' => $newRecord->id]);
        $this->actingAs($this->supervisor);

        Livewire::test(ListTeamTickets::class)
            ->set('activeTab', 'all')
            ->filterTable('assignee', [$newRecord->id])
            ->assertCanSeeTableRecords([$mine, $moved])
            ->assertCanNotSeeTableRecords([$pool])
            ->resetTableFilters()
            ->filterTable('topic_id', [$topic->id])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$pool, $moved])
            ->resetTableFilters()
            ->filterTable('created_between', ['from' => '2026-11-17'])
            ->assertCanNotSeeTableRecords([$mine, $pool]);
    }

    public function test_filters_never_offer_people_from_requests_out_of_sight(): void
    {
        $this->started();
        $this->actingAs($this->person()[1]);

        Livewire::test(ListTeamTickets::class)
            ->assertDontSee($this->tech->employee->name)
            ->assertDontSee($this->requester->name);
    }
}
