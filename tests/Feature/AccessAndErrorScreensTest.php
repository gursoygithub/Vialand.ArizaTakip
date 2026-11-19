<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Resources\TeamResource;
use App\Filament\Resources\TeamTicketResource;
use App\Filament\Resources\TeamTicketResource\Pages\ViewTeamTicket;
use App\Filament\Widgets\MyRequestQueue;
use App\Filament\Widgets\OpenRequestsByTypeChart;
use App\Filament\Widgets\RequestOverviewStats;
use App\Filament\Widgets\RequestsByStatusChart;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Dashboard widgets follow the role's permissions, error pages explain
 * themselves in Turkish, and request images open in a lightbox.
 */
class AccessAndErrorScreensTest extends TestCase
{
    use RefreshDatabase;

    private const WIDGETS = [
        RequestOverviewStats::class,
        MyRequestQueue::class,
        RequestsByStatusChart::class,
        OpenRequestsByTypeChart::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['legacy.enabled' => false, 'app.debug' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        Notification::fake();
        $this->seed(PermissionSeeder::class);
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    private function person(): array
    {
        $email = fake()->unique()->safeEmail();
        $user = User::factory()->create(['email' => $email, 'username' => 'u'.fake()->unique()->numerify('#######'), 'status' => UserStatusEnum::ACTIVE]);
        $user->givePermissionTo(['view_any_ticket', 'view_ticket', 'create_ticket']);

        return [Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE]), $user];
    }

    /**
     * @return array{0: Ticket, 1: User, 2: User, 3: User} ticket, requester, supervisor, tech
     */
    private function startedTicket(): array
    {
        $requester = $this->person()[1];
        [$supervisorEmployee, $supervisor] = $this->person();
        [$techEmployee, $tech] = $this->person();
        $this->actingAs($requester);
        $team = Team::factory()->create(['supervisor_employee_id' => $supervisorEmployee->id]);
        $team->members()->attach($techEmployee->id);
        $workflow = app(TicketWorkflowService::class);
        $ticket = $workflow->open($requester, $team, TaskPriorityEnum::Urgent, 'Turnike çalışmıyor.');
        $ticket = $workflow->startWork($workflow->assign($ticket, $techEmployee, $supervisor), $tech);

        return [$ticket, $requester, $supervisor, $tech];
    }

    // ── Dashboard widgets ───────────────────────────────────────────────

    public function test_dashboard_widgets_need_their_permission(): void
    {
        $user = $this->person()[1];
        $this->actingAs($user);

        foreach (self::WIDGETS as $widget) {
            $this->assertFalse($widget::canView(), $widget);
        }
        $this->get('/')->assertOk()->assertDontSeeLivewire(MyRequestQueue::class);

        foreach (self::WIDGETS as $widget) {
            Permission::findOrCreate('widget_'.class_basename($widget), 'web');
        }
        $user->givePermissionTo('widget_MyRequestQueue');

        $this->assertTrue(MyRequestQueue::canView());
        $this->assertFalse(RequestOverviewStats::canView());
        $this->get('/')->assertOk()->assertSeeLivewire(MyRequestQueue::class);
    }

    public function test_super_admin_sees_every_widget(): void
    {
        foreach (self::WIDGETS as $widget) {
            Permission::findOrCreate('widget_'.class_basename($widget), 'web');
        }
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        foreach (self::WIDGETS as $widget) {
            $this->assertTrue($widget::canView(), $widget);
        }
    }

    // ── Error pages ─────────────────────────────────────────────────────

    public function test_request_out_of_sight_explains_itself(): void
    {
        [$ticket] = $this->startedTicket();
        $this->actingAs($this->person()[1]);

        $this->get(TeamTicketResource::getUrl('view', ['record' => $ticket]))
            ->assertNotFound()
            ->assertSee(__('ui.error_404_title'))
            ->assertSee(__('ui.error_404_hint'))
            ->assertSee(__('ui.error_go_home'));
    }

    public function test_screen_without_permission_explains_itself(): void
    {
        $this->actingAs($this->person()[1]);

        $this->get(TeamResource::getUrl('index'))
            ->assertForbidden()
            ->assertSee(__('ui.error_403_title'))
            ->assertSee(__('ui.error_403_hint'));
    }

    public function test_server_error_page_is_turkish(): void
    {
        $html = view('errors.500')->render();

        $this->assertStringContainsString(e(__('ui.error_500_title')), $html);
        $this->assertStringContainsString(e(__('ui.error_500_hint')), $html);
    }

    // ── Request screen ──────────────────────────────────────────────────

    public function test_images_open_in_a_lightbox_and_pdfs_in_a_new_tab(): void
    {
        Storage::fake('public');
        [$ticket, $requester] = $this->startedTicket();
        $ticket->addMedia(UploadedFile::fake()->image('ariza.jpg'))->toMediaCollection('task_attachments');
        $ticket->addMedia(UploadedFile::fake()->create('servis.pdf', 10, 'application/pdf'))->toMediaCollection('task_attachments');
        $this->actingAs($requester);

        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->assertSeeHtml('x-on:click.prevent="show(0)"')
            ->assertSeeHtml('target="_blank"')
            ->assertSeeHtml('x-teleport="body"');
    }

    public function test_hold_after_the_deadline_says_it_does_not_lift_the_breach(): void
    {
        [$ticket, , , $tech] = $this->startedTicket();
        $this->actingAs($tech);

        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->mountAction('hold')
            ->assertDontSee(__('ui.hold_after_breach_note'));

        $this->travel(3)->hours();

        Livewire::test(ViewTeamTicket::class, ['record' => $ticket->id])
            ->mountAction('hold')
            ->assertSee(__('ui.hold_after_breach_note'))
            ->setActionData(['reason' => HoldReasonEnum::AwaitingParts->value])
            ->callMountedAction()
            ->assertHasNoActionErrors();
    }
}
