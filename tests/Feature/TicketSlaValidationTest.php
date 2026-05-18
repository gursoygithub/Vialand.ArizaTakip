<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Filament\Resources\TicketResource\Pages\CreateTicket;
use App\Models\Area;
use App\Models\SlaPolicy;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketSlaValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Area $area;
    private SubArea $subArea;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->user->givePermissionTo(['view_any_ticket', 'create_ticket', 'ticket.view.all']);

        $this->area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $this->subArea = SubArea::factory()->create(['area_id' => $this->area->id]);
        $this->unit    = Unit::factory()->create();
    }

    /**
     * Returns the minimum valid form payload.
     *
     * sub_area_id: the column is NOT NULL on SQLite (the nullable migration is
     * MySQL-only), so we must provide a value even though the form field is
     * optional. The SLA policy is seeded with sub_area_id = null (L2 fallback),
     * so resolvePolicy() will fail L1 (area+sub_area+unit+priority) and succeed
     * at L2 (area+null_sub_area+unit+priority) — the correct lookup path.
     *
     * type_id is a Hidden field with ->default(TaskTypeEnum::OPERATION) that
     * Filament sets when the form mounts — NOT included here so the form's own
     * initialisation is exercised.
     */
    private function formData(array $overrides = []): array
    {
        return array_merge([
            'priority'    => TaskPriorityEnum::Medium->value,
            'area_id'     => $this->area->id,
            'sub_area_id' => $this->subArea->id,
            'unit_id'     => $this->unit->id,
            'task_date'   => now()->toDateString(),
            'description' => 'Test description here.',
        ], $overrides);
    }

    /**
     * Test 1 — beforeCreate() blocks submission when no SLA policy exists for
     * the (area, unit, priority) combination.  The ValidationException must
     * surface as a field error on unit_id and no ticket row must be written.
     */
    public function test_ticket_creation_blocked_without_sla_policy(): void
    {
        // No SlaPolicy row created — resolvePolicy() will return null.

        Livewire::test(CreateTicket::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasErrors('data.unit_id');

        $this->assertDatabaseCount('tickets', 0);
    }

    /**
     * Test 2 — Submission succeeds when a matching SLA policy exists.
     * The Observer's creating() hook sets sla_deadline from the policy, so
     * the persisted ticket must have a non-null sla_deadline.
     */
    public function test_ticket_creation_succeeds_with_matching_sla_policy(): void
    {
        SlaPolicy::factory()->create([
            'area_id'     => $this->area->id,
            'unit_id'     => $this->unit->id,
            'priority'    => TaskPriorityEnum::Medium->value,
            'sub_area_id' => null,
        ]);

        Livewire::test(CreateTicket::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasNoErrors();

        $ticket = Ticket::first();
        $this->assertNotNull($ticket, 'Ticket should have been created.');
        $this->assertNotNull($ticket->sla_deadline, 'sla_deadline must be set by the observer when a policy exists.');
    }

    /**
     * Test 3 — Blocked when a policy exists for a different priority (Urgent)
     * but the form submits with Low priority (priority mismatch).
     * resolvePolicy() traverses all four fallback levels — none match Low here,
     * so beforeCreate() throws and no ticket is written.
     */
    public function test_ticket_creation_blocked_on_priority_mismatch(): void
    {
        // SLA policy covers Urgent (Acil) only.
        SlaPolicy::factory()->create([
            'area_id'     => $this->area->id,
            'unit_id'     => $this->unit->id,
            'priority'    => TaskPriorityEnum::Urgent->value,
            'sub_area_id' => null,
        ]);

        Livewire::test(CreateTicket::class)
            ->fillForm($this->formData(['priority' => TaskPriorityEnum::Low->value]))
            ->call('create')
            ->assertHasErrors('data.unit_id');

        $this->assertDatabaseCount('tickets', 0);
    }
}
