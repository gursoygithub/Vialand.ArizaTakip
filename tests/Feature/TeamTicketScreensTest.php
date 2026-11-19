<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\FormFieldTypeEnum;
use App\Enums\LocationRequirementEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Resources\TeamTicketResource;
use App\Filament\Resources\TeamTicketResource\Pages\CreateTeamTicket;
use App\Filament\Resources\TeamTicketResource\Pages\ListTeamTickets;
use App\Filament\Resources\TeamTicketResource\Pages\ViewTeamTicket;
use App\Filament\Resources\TicketResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Notifications\TicketCommentNotification;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Filament\Tables\Columns\Column;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TeamTicketScreensTest extends TestCase
{
    use RefreshDatabase;

    private TicketType $type;

    private Team $team;

    private User $requester;

    private User $supervisor;

    private User $tech;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        $this->seed(PermissionSeeder::class);

        $this->requester = $this->person()[1];
        [$supervisorEmployee, $this->supervisor] = $this->person();
        [$techEmployee, $this->tech] = $this->person();
        $this->outsider = $this->person()[1];

        $this->actingAs($this->requester);

        $this->type = TicketType::factory()->create([
            'name' => 'IT Destek',
            'location_requirement' => LocationRequirementEnum::Hidden,
            'form_fields' => [
                ['key' => 'asset_no', 'label' => 'Demirbaş No', 'type' => FormFieldTypeEnum::Text->value, 'required' => true],
                ['key' => 'device', 'label' => 'Cihaz', 'type' => FormFieldTypeEnum::Select->value, 'options' => ['Yazıcı', 'Bilgisayar'], 'required' => false],
            ],
        ]);
        $this->team = Team::factory()->create([
            'ticket_type_id' => $this->type->id,
            'supervisor_employee_id' => $supervisorEmployee->id,
        ]);
        $this->team->members()->attach($techEmployee->id);
    }

    /**
     * Panel user with the ticket permissions a normal role gets.
     *
     * @return array{0: Employee, 1: User}
     */
    private function person(?Company $company = null): array
    {
        $email = fake()->unique()->safeEmail();
        $user = User::factory()->create([
            'email' => $email,
            'username' => 'u'.fake()->unique()->numerify('#######'),
            'status' => UserStatusEnum::ACTIVE,
        ]);
        $user->givePermissionTo(['view_any_team::ticket', 'view_team::ticket', 'create_team::ticket']);
        $employee = Employee::factory()->create([
            'email' => $email,
            'status' => ActiveStatusEnum::ACTIVE,
            'company_id' => $company?->id,
        ]);

        return [$employee, $user];
    }

    private function openTicket(?User $requester = null): Ticket
    {
        return app(TicketWorkflowService::class)->open(
            $requester ?? $this->requester,
            $this->team,
            TaskPriorityEnum::High,
            'Muhasebe yazıcısı çıktı vermiyor.',
            ['asset_no' => 'DMR-1001'],
        );
    }

    // ── Create ──────────────────────────────────────────────────────────

    public function test_requester_opens_a_request_with_type_team_and_custom_fields(): void
    {
        Livewire::test(CreateTeamTicket::class)
            ->fillForm([
                'ticket_type_id' => $this->type->id,
                'team_id' => $this->team->id,
                'priority' => TaskPriorityEnum::Urgent->value,
                'custom_fields' => ['asset_no' => 'DMR-42', 'device' => 'Yazıcı'],
                'description' => 'Yazıcı kağıt sıkıştırıyor ve çıktı vermiyor.',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $ticket = Ticket::whereNotNull('team_id')->sole();
        $this->assertSame($this->team->id, $ticket->team_id);
        $this->assertSame($this->type->id, $ticket->ticket_type_id);
        $this->assertSame(TaskStatusEnum::OPEN, $ticket->status);
        $this->assertSame(['asset_no' => 'DMR-42', 'device' => 'Yazıcı'], $ticket->custom_fields);
        $this->assertSame($this->requester->id, $ticket->created_by);
        $this->assertNotNull($ticket->currentCycle);
    }

    public function test_required_custom_field_is_validated_on_the_form(): void
    {
        Livewire::test(CreateTeamTicket::class)
            ->fillForm([
                'ticket_type_id' => $this->type->id,
                'team_id' => $this->team->id,
                'priority' => TaskPriorityEnum::Low->value,
                'description' => 'Açıklama yeterince uzun.',
            ])
            ->call('create')
            ->assertHasFormErrors(['custom_fields.asset_no' => 'required']);
    }

    /**
     * Mirrors the browser: the type is picked first (which resets the custom
     * fields), then the team, then the type's own fields one by one.
     */
    public function test_required_select_custom_field_is_accepted_when_filled_step_by_step(): void
    {
        $this->type->update(['form_fields' => [
            ['key' => 'device_kind', 'label' => 'Konu', 'type' => FormFieldTypeEnum::Select->value, 'options' => ['Bilgisayar', 'Yazıcı'], 'required' => true],
            ['key' => 'asset_no', 'label' => 'Demirbaş No', 'type' => FormFieldTypeEnum::Text->value, 'required' => false],
        ]]);

        Livewire::test(CreateTeamTicket::class)
            ->fillForm(['ticket_type_id' => $this->type->id])
            ->assertSet('data.custom_fields', ['device_kind' => null, 'asset_no' => null])
            ->fillForm(['team_id' => $this->team->id])
            ->set('data.custom_fields.device_kind', 'Bilgisayar')
            ->set('data.custom_fields.asset_no', 'PC01')
            ->set('data.description', 'merhaba bu bir testtir.')
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertSame(
            ['device_kind' => 'Bilgisayar', 'asset_no' => 'PC01'],
            Ticket::whereNotNull('team_id')->sole()->custom_fields,
        );
    }

    public function test_team_must_belong_to_the_selected_type(): void
    {
        $otherTeam = Team::factory()->create();

        Livewire::test(CreateTeamTicket::class)
            ->fillForm([
                'ticket_type_id' => $this->type->id,
                'team_id' => $otherTeam->id,
                'priority' => TaskPriorityEnum::Low->value,
                'custom_fields' => ['asset_no' => 'X'],
                'description' => 'Açıklama yeterince uzun.',
            ])
            ->call('create')
            ->assertHasFormErrors(['team_id']);

        $this->assertSame(0, Ticket::whereNotNull('team_id')->count());
    }

    public function test_location_is_required_when_the_type_asks_for_it(): void
    {
        $this->type->update(['location_requirement' => LocationRequirementEnum::Required, 'form_fields' => []]);

        Livewire::test(CreateTeamTicket::class)
            ->fillForm([
                'ticket_type_id' => $this->type->id,
                'team_id' => $this->team->id,
                'priority' => TaskPriorityEnum::Low->value,
                'description' => 'Açıklama yeterince uzun.',
            ])
            ->call('create')
            ->assertHasFormErrors(['area_id' => 'required', 'sub_area_id' => 'required']);
    }

    public function test_type_can_be_preselected_from_the_url(): void
    {
        $this->get(TeamTicketResource::getUrl('create', ['type' => $this->type->id]))->assertOk();

        Livewire::withQueryParams(['type' => $this->type->id])
            ->test(CreateTeamTicket::class)
            ->assertFormSet(['ticket_type_id' => $this->type->id]);
    }

    // ── Visibility ──────────────────────────────────────────────────────

    public function test_each_person_sees_only_what_concerns_them(): void
    {
        $ticket = $this->openTicket();

        foreach ([$this->requester, $this->supervisor, $this->tech] as $user) {
            $this->assertTrue(Ticket::visibleInTeamWorkflow($user)->whereKey($ticket->id)->exists(), $user->username);
        }

        $this->assertFalse(Ticket::visibleInTeamWorkflow($this->outsider)->whereKey($ticket->id)->exists());

        $this->actingAs($this->outsider);
        $this->get(TeamTicketResource::getUrl('view', ['record' => $ticket]))->assertNotFound();
    }

    public function test_former_assignee_keeps_seeing_the_ticket(): void
    {
        $ticket = $this->openTicket();
        [$otherEmployee, $other] = $this->person();
        $this->team->members()->attach($otherEmployee->id);
        $workflow = app(TicketWorkflowService::class);

        $ticket = $workflow->assign($ticket, $otherEmployee, $this->supervisor);
        $workflow->assign($ticket, $this->tech->employee, $this->supervisor);
        $this->team->members()->detach($otherEmployee->id);

        $this->assertTrue(Ticket::visibleInTeamWorkflow($other)->whereKey($ticket->id)->exists());
    }

    public function test_company_scope_permission_shows_requests_of_own_company(): void
    {
        $company = Company::factory()->create();
        [, $colleague] = $this->person($company);
        [, $manager] = $this->person($company);
        $ticket = $this->openTicket($colleague);

        $this->assertFalse(Ticket::visibleInTeamWorkflow($manager)->whereKey($ticket->id)->exists());

        $manager->givePermissionTo('ticket.view.company');
        $this->assertTrue(Ticket::visibleInTeamWorkflow($manager->fresh())->whereKey($ticket->id)->exists());
    }

    public function test_view_all_permission_sees_every_team_ticket(): void
    {
        $ticket = $this->openTicket();
        $this->outsider->givePermissionTo('ticket.view.all');

        $this->assertTrue(Ticket::visibleInTeamWorkflow($this->outsider->fresh())->whereKey($ticket->id)->exists());
    }

    public function test_tabs_split_the_work_queue(): void
    {
        $ticket = $this->openTicket();

        $this->actingAs($this->tech);
        Livewire::test(ListTeamTickets::class)
            ->set('activeTab', 'team')
            ->assertCanSeeTableRecords([$ticket])
            ->set('activeTab', 'assigned')
            ->assertCanNotSeeTableRecords([$ticket]);

        $this->actingAs($this->requester);
        Livewire::test(ListTeamTickets::class)
            ->set('activeTab', 'created')
            ->assertCanSeeTableRecords([$ticket]);
    }

    public function test_legacy_ticket_screens_never_show_team_tickets(): void
    {
        $ticket = $this->openTicket();
        $this->requester->givePermissionTo('ticket.view.all');

        $this->assertFalse(TicketResource::getEloquentQuery()->whereKey($ticket->id)->exists());
        $this->get(TicketResource::getUrl('view', ['record' => $ticket]))->assertNotFound();
    }

    public function test_region_and_location_columns_can_be_shown(): void
    {
        $this->openTicket();

        $hiddenButToggleable = fn (Column $column): bool => $column->isToggleable() && $column->isToggledHiddenByDefault();

        Livewire::test(ListTeamTickets::class)
            ->assertTableColumnExists('area.name', $hiddenButToggleable)
            ->assertTableColumnExists('subArea.name', $hiddenButToggleable);
    }

    // ── Actions on the detail page ──────────────────────────────────────

    public function test_buttons_follow_the_role(): void
    {
        $ticket = $this->openTicket();

        $this->actingAs($this->requester);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->assertActionHidden('assign')
            ->assertActionHidden('startWork')
            ->assertActionVisible('cancel');

        $this->actingAs($this->supervisor);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->assertActionVisible('assign')
            ->assertActionVisible('reject')
            ->assertActionVisible('transfer')
            ->assertActionDoesNotExist('changePriority');

        $this->actingAs($this->tech);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->assertActionHidden('assign')
            ->assertActionVisible('selfAssign');
    }

    public function test_full_flow_through_the_detail_page(): void
    {
        $ticket = $this->openTicket();

        $this->actingAs($this->supervisor);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->callAction('assign', data: ['employee_id' => $this->tech->employee->id])
            ->assertHasNoActionErrors();
        $this->assertSame(TaskStatusEnum::ASSIGNED, $ticket->fresh()->status);

        $this->actingAs($this->tech);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->callAction('startWork')
            ->callAction('resolve', data: ['note' => 'Toner değiştirildi.']);
        $this->assertSame(TaskStatusEnum::RESOLVED, $ticket->fresh()->status);

        $this->actingAs($this->requester);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->assertActionVisible('rejectResolution')
            ->callAction('confirm');

        $ticket->refresh();
        $this->assertSame(TaskStatusEnum::CLOSED, $ticket->status);
        $this->assertSame('on_time', $ticket->currentCycle->outcome->value);
    }

    public function test_requester_rejects_the_resolution_from_the_page(): void
    {
        $workflow = app(TicketWorkflowService::class);
        $ticket = $workflow->resolve(
            $workflow->startWork($workflow->assign($this->openTicket(), $this->tech->employee, $this->supervisor), $this->tech),
            $this->tech,
        );

        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->callAction('rejectResolution', data: ['reason' => 'Hâlâ çıktı vermiyor.'])
            ->assertHasNoActionErrors();

        $this->assertSame(TaskStatusEnum::IN_PROGRESS, $ticket->fresh()->status);
    }

    public function test_sla_label_reads_as_time_left(): void
    {
        $ticket = $this->openTicket()->load('currentCycle');

        $label = TeamTicketResource::slaLabel($ticket);

        $this->assertStringEndsWith(__('ui.sla_due', ['time' => '']), $label.'');
        $this->assertStringNotContainsString('sonra', $label);
        $this->assertStringNotContainsString('önce', $label);
    }

    public function test_links_point_to_the_right_screen(): void
    {
        $ticket = $this->openTicket();

        $this->assertSame(url('/requests/'.$ticket->id), $ticket->panelUrl());

        $legacy = Ticket::factory()->create();
        $this->assertSame(url('/tickets/'.$legacy->id), $legacy->panelUrl());

        $data = (new TicketCommentNotification($ticket, $this->requester, 'Not'))->toDatabase($this->tech);
        $this->assertSame(url('/requests/'.$ticket->id), $data['actions'][0]['url']);
    }

    public function test_people_show_name_and_company_and_sla_targets_as_badges(): void
    {
        $ticket = $this->openTicket();

        $this->get(TeamTicketResource::getUrl('view', ['record' => $ticket]))
            ->assertOk()
            ->assertSee($this->team->supervisor->name)
            ->assertSee(__('ui.sla_target_response', ['time' => TeamTicketResource::humanMinutes(60)]))
            ->assertSee(__('ui.sla_target_resolution', ['time' => TeamTicketResource::humanMinutes(480)]));
    }

    public function test_detail_page_shows_sla_and_custom_fields(): void
    {
        $ticket = $this->openTicket();

        $this->get(TeamTicketResource::getUrl('view', ['record' => $ticket]))
            ->assertOk()
            ->assertSee('DMR-1001')
            ->assertSee(__('ui.sla_resolution'))
            ->assertSee(__('ui.sla_due', ['time' => '8 sa']))
            ->assertSee(__('ui.sla_used', ['percent' => 0]))
            ->assertSee(__('ui.request_in_pool'));
    }

    public function test_detail_page_shows_attachments_as_fixed_size_tiles(): void
    {
        Storage::fake('s3');
        $ticket = $this->openTicket();
        $ticket->addMedia(UploadedFile::fake()->image('sistem-odasi.png', 2400, 300))->toMediaCollection('task_attachments');
        $ticket->addMedia(UploadedFile::fake()->create('servis-formu.pdf', 50, 'application/pdf'))->toMediaCollection('task_attachments');

        $html = $this->get(TeamTicketResource::getUrl('view', ['record' => $ticket]))
            ->assertOk()
            ->assertSee('sistem-odasi.png')
            ->assertSee('servis-formu.pdf')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'alt="sistem-odasi.png"'), 'only the image gets a thumbnail');
        $this->assertStringContainsString('object-fit:contain', $html);
    }

    public function test_description_is_shown_framed_and_escaped(): void
    {
        $ticket = $this->openTicket();
        $ticket->update(['description' => 'Yazıcı <b>çalışmıyor</b>']);

        $this->get(TeamTicketResource::getUrl('view', ['record' => $ticket]))
            ->assertOk()
            ->assertSee('border-left:4px solid #3b82f6', false)
            ->assertSee('Yazıcı &lt;b&gt;çalışmıyor&lt;/b&gt;', false);
    }

    public function test_supervisor_goes_back_to_the_list_after_a_transfer(): void
    {
        $ticket = $this->openTicket();
        $otherTeam = Team::factory()->create(['ticket_type_id' => $this->type->id]);

        $this->actingAs($this->supervisor);
        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->callAction('transfer', ['team_id' => $otherTeam->id, 'reason' => 'Diğer ekibin işi.'])
            ->assertRedirect(TeamTicketResource::getUrl('index'));

        $this->assertSame($otherTeam->id, $ticket->fresh()->team_id);
    }
}
