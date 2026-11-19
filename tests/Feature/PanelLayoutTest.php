<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Widgets\MyRequestQueue;
use App\Filament\Widgets\RequestOverviewStats;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Legacy-flow switch and the team-model dashboard.
 */
class PanelLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Notification::fake();
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

    public function test_dashboard_shows_only_what_concerns_the_viewer(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        $requester = $this->person()[1];
        [$supervisorEmployee, $supervisor] = $this->person();
        [$techEmployee, $tech] = $this->person();
        $outsider = $this->person()[1];

        $this->actingAs($requester);
        $team = Team::factory()->create(['supervisor_employee_id' => $supervisorEmployee->id]);
        $team->members()->attach($techEmployee->id);
        $workflow = app(TicketWorkflowService::class);

        $assigned = $workflow->assign(
            $workflow->open($requester, $team, TaskPriorityEnum::High, 'Birinci talep açıklaması.'),
            $techEmployee,
            $supervisor,
        );
        $pool = $workflow->open($requester, $team, TaskPriorityEnum::Low, 'İkinci talep açıklaması.');

        $this->actingAs($tech);
        Livewire::test(MyRequestQueue::class)
            ->assertCanSeeTableRecords([$assigned, $pool])
            ->assertSee(__('ui.dashboard_reason_assigned'))
            ->assertSee(__('ui.dashboard_reason_pool'));
        Livewire::test(RequestOverviewStats::class)
            ->assertSee(__('ui.dashboard_open_desc', ['pool' => 1]))
            ->assertSee(__('ui.dashboard_mine_desc', ['count' => 0]));

        $this->actingAs($outsider);
        Livewire::test(MyRequestQueue::class)
            ->assertCanNotSeeTableRecords([$assigned, $pool]);
        Livewire::test(RequestOverviewStats::class)
            ->assertSee(__('ui.dashboard_open_desc', ['pool' => 0]));
    }

    public function test_requester_sees_resolutions_waiting_for_confirmation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        $requester = $this->person()[1];
        [$supervisorEmployee, $supervisor] = $this->person();
        [$techEmployee, $tech] = $this->person();
        $this->actingAs($requester);
        $team = Team::factory()->create(['supervisor_employee_id' => $supervisorEmployee->id]);
        $team->members()->attach($techEmployee->id);
        $workflow = app(TicketWorkflowService::class);

        $ticket = $workflow->resolve(
            $workflow->startWork($workflow->assign($workflow->open($requester, $team, TaskPriorityEnum::Medium, 'Açıklama metni.'), $techEmployee, $supervisor), $tech),
            $tech,
        );

        $this->actingAs($requester);
        Livewire::test(MyRequestQueue::class)
            ->assertCanSeeTableRecords([$ticket])
            ->assertSee(__('ui.dashboard_reason_confirm'));
    }
}
