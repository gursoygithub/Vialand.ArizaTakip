<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Area;
use App\Models\Employee;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
    }

    private function makeTicket(array $overrides = []): Ticket
    {
        $area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        return Ticket::factory()->create(array_merge([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'priority'    => TaskPriorityEnum::Medium,
            'created_by'  => $this->actor->id,
        ], $overrides));
    }

    /**
     * A ticket created without an assignee must show "Talep Açıldı"
     * but NOT an "Atandı:" card.
     */
    public function test_open_ticket_shows_only_talep_acildi(): void
    {
        $ticket = $this->makeTicket(['status' => TaskStatusEnum::OPEN]);

        $html = (string) ViewTicket::renderTimelinePublic($ticket);

        $this->assertStringContainsString('Talep Açıldı', $html);
        $this->assertStringNotContainsString('Atandı:', $html);
    }

    /**
     * A ticket created directly with an assignee (status=ASSIGNED) must show
     * BOTH "Talep Açıldı" AND "Atandı: <employee name>".
     */
    public function test_assigned_at_creation_shows_both_cards(): void
    {
        $employee = Employee::factory()->create([
            'name'   => 'Mehmet Yılmaz',
            'status' => ActiveStatusEnum::ACTIVE,
        ]);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $employee->id,
        ]);

        $html = (string) ViewTicket::renderTimelinePublic($ticket);

        $this->assertStringContainsString('Talep Açıldı', $html);
        $this->assertStringContainsString('Atandı: Mehmet Yılmaz', $html);
    }

    /**
     * Regression: a reassign card written after creation must still appear.
     * This exercises the __reassign__ branch independently of the creation fix.
     */
    public function test_later_reassign_card_still_appears(): void
    {
        $employee = Employee::factory()->create([
            'name'   => 'Ali Veli',
            'status' => ActiveStatusEnum::ACTIVE,
        ]);

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::ASSIGNED,
            'employee_id' => $employee->id,
        ]);

        // Simulate a reassign history row written by TicketService::reassign()
        TicketStatusHistory::create([
            'ticket_id'   => $ticket->id,
            'from_status' => TaskStatusEnum::ASSIGNED->value,
            'to_status'   => TaskStatusEnum::ASSIGNED->value,
            'changed_by'  => $this->actor->id,
            'note'        => \App\Services\TicketService::REASSIGN_NOTE_PREFIX . 'Ali Veli → Yeni Kişi',
            'created_at'  => now()->addMinute(),
        ]);

        $html = (string) ViewTicket::renderTimelinePublic($ticket);

        $this->assertStringContainsString('Talep Açıldı', $html);
        $this->assertStringContainsString('Atandı: Ali Veli', $html);
        $this->assertStringContainsString('Personel Değişikliği', $html);
    }
}
