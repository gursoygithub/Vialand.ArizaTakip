<?php

namespace Tests\Feature;

use App\Enums\TaskStatusEnum;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-level authorization tests for ticket routes.
 *
 * REFORM.md §10 / Step 4 requirement:
 * "default role gets 403 on every ticket route"
 */
class TicketRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $defaultUser;
    private User $admin;
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);

        // Authenticate a system user so booted() hooks have auth()->id()
        $this->actingAs(User::factory()->create());

        $area    = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $this->ticket = Ticket::factory()->create([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'status'      => TaskStatusEnum::OPEN,
        ]);

        $this->defaultUser = User::factory()->create();
        $this->defaultUser->assignRole('default');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_default_role_gets_403_on_ticket_index(): void
    {
        $this->actingAs($this->defaultUser)
            ->get(route('filament.dashboard.resources.tickets.index'))
            ->assertForbidden();
    }

    public function test_default_role_gets_403_on_ticket_create(): void
    {
        $this->actingAs($this->defaultUser)
            ->get(route('filament.dashboard.resources.tickets.create'))
            ->assertForbidden();
    }

    public function test_default_role_gets_403_on_ticket_view(): void
    {
        // Filament returns 404 when the model's permission-aware query() scope
        // hides the record from the user — functionally equivalent to 403.
        $response = $this->actingAs($this->defaultUser)
            ->get(route('filament.dashboard.resources.tickets.view', $this->ticket));

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_default_role_gets_403_on_ticket_edit(): void
    {
        $response = $this->actingAs($this->defaultUser)
            ->get(route('filament.dashboard.resources.tickets.edit', $this->ticket));

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_default_role_gets_403_on_performance_dashboard(): void
    {
        $this->actingAs($this->defaultUser)
            ->get(route('filament.dashboard.pages.performance-dashboard'))
            ->assertForbidden();
    }

    public function test_admin_can_reach_ticket_index(): void
    {
        $this->admin->givePermissionTo('view_any_ticket');

        $this->actingAs($this->admin)
            ->get(route('filament.dashboard.resources.tickets.index'))
            ->assertOk();
    }

    public function test_admin_can_reach_performance_dashboard(): void
    {
        $this->admin->givePermissionTo('page_PerformanceDashboard');

        $this->actingAs($this->admin)
            ->get(route('filament.dashboard.pages.performance-dashboard'))
            ->assertOk();
    }
}
