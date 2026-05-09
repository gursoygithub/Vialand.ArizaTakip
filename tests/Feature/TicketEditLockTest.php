<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TicketResource\Pages\EditTicket;
use App\Models\Area;
use App\Models\Employee;
use App\Models\Group;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketEditLockTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private Ticket $ticket;
    private Area $area;
    private SubArea $subArea;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->creator = User::factory()->create();
        $this->actingAs($this->creator);

        $this->area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $this->subArea = SubArea::factory()->create(['area_id' => $this->area->id]);
        $this->unit    = Unit::factory()->create();

        $this->ticket = Ticket::factory()->create([
            'area_id'     => $this->area->id,
            'sub_area_id' => $this->subArea->id,
            'unit_id'     => $this->unit->id,
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => $this->creator->id,
        ]);

        // Grant edit permissions
        $this->creator->givePermissionTo([
            'view_any_ticket',
            'view_ticket',
            'create_ticket',
            'update_ticket',
            'ticket.view.all',
        ]);
    }

    /**
     * Submitting the edit form with a different employee_id must NOT change the DB value.
     * The field is dehydrated(false) on edit, so Filament strips it before save.
     */
    public function test_employee_id_cannot_be_changed_via_edit_form(): void
    {
        $otherEmployee = Employee::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);

        $originalEmployeeId = $this->ticket->employee_id;

        Livewire::test(EditTicket::class, ['record' => $this->ticket->getKey()])
            ->fillForm(['employee_id' => $otherEmployee->id])
            ->call('save');

        $this->assertEquals($originalEmployeeId, $this->ticket->fresh()->employee_id);
    }

    /**
     * Submitting with a different area_id / sub_area_id / unit_id / group_id must NOT
     * mutate those columns — all four are locked via dehydrated(false) on edit.
     */
    public function test_structural_fields_cannot_be_changed_via_edit_form(): void
    {
        $newArea    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $newSubArea = SubArea::factory()->create(['area_id' => $newArea->id]);
        $newUnit    = Unit::factory()->create();
        $newGroup   = Group::factory()->create(['area_id' => $newArea->id]);

        $original = $this->ticket->only(['area_id', 'sub_area_id', 'unit_id', 'group_id']);

        Livewire::test(EditTicket::class, ['record' => $this->ticket->getKey()])
            ->fillForm([
                'area_id'     => $newArea->id,
                'sub_area_id' => $newSubArea->id,
                'unit_id'     => $newUnit->id,
                'group_id'    => $newGroup->id,
            ])
            ->call('save');

        $fresh = $this->ticket->fresh()->only(['area_id', 'sub_area_id', 'unit_id', 'group_id']);
        $this->assertEquals($original, $fresh);
    }

    /**
     * Regression: priority (an allowed edit field) must still be saveable.
     */
    public function test_priority_can_still_be_changed_on_edit(): void
    {
        $this->assertNotEquals(TaskPriorityEnum::High, $this->ticket->priority);

        Livewire::test(EditTicket::class, ['record' => $this->ticket->getKey()])
            ->fillForm(['priority' => TaskPriorityEnum::High])
            ->call('save');

        $this->assertEquals(TaskPriorityEnum::High, $this->ticket->fresh()->priority);
    }

    /**
     * Regression: description (an allowed edit field) must still be saveable.
     */
    public function test_description_can_still_be_changed_on_edit(): void
    {
        Livewire::test(EditTicket::class, ['record' => $this->ticket->getKey()])
            ->fillForm(['description' => 'Updated description text for regression test.'])
            ->call('save');

        $this->assertEquals(
            'Updated description text for regression test.',
            $this->ticket->fresh()->description,
        );
    }
}
