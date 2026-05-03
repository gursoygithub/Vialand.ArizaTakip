<?php

namespace Tests\Feature;

use App\Enums\TaskStatusEnum;
use App\Exceptions\TicketTransitionException;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\Unit;
use App\Models\User;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private TicketService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
        $this->service = app(TicketService::class);
    }

    private function makeTicket(array $overrides = []): Ticket
    {
        $area    = Area::factory()->create(['status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        return Ticket::factory()->create(array_merge([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'status'      => TaskStatusEnum::OPEN,
        ], $overrides));
    }

    public function test_ticket_no_is_auto_generated(): void
    {
        $ticket = $this->makeTicket();

        $this->assertNotNull($ticket->ticket_no);
        $this->assertMatchesRegularExpression('/^TKT-\d{4}-\d{5}$/', $ticket->ticket_no);
    }

    public function test_valid_status_transitions_succeed(): void
    {
        $ticket = $this->makeTicket();

        $this->service->transition($ticket, TaskStatusEnum::ASSIGNED, $this->actor);
        $this->assertEquals(TaskStatusEnum::ASSIGNED, $ticket->fresh()->status);

        $this->service->transition($ticket->fresh(), TaskStatusEnum::IN_PROGRESS, $this->actor);
        $this->assertEquals(TaskStatusEnum::IN_PROGRESS, $ticket->fresh()->status);

        $this->service->transition($ticket->fresh(), TaskStatusEnum::RESOLVED, $this->actor);
        $this->assertEquals(TaskStatusEnum::RESOLVED, $ticket->fresh()->status);

        $this->service->transition($ticket->fresh(), TaskStatusEnum::CLOSED, $this->actor);
        $this->assertEquals(TaskStatusEnum::CLOSED, $ticket->fresh()->status);
    }

    public function test_invalid_transition_throws_exception(): void
    {
        $ticket = $this->makeTicket(['status' => TaskStatusEnum::OPEN]);

        $this->expectException(TicketTransitionException::class);
        // open → resolved is not allowed (must go through assigned/in_progress)
        $this->service->transition($ticket, TaskStatusEnum::RESOLVED, $this->actor);
    }

    public function test_status_history_logged_on_every_transition(): void
    {
        $ticket = $this->makeTicket();

        // The created hook already logs one row
        $base = TicketStatusHistory::where('ticket_id', $ticket->id)->count();

        $this->service->transition($ticket, TaskStatusEnum::ASSIGNED, $this->actor, 'first');
        $this->service->transition($ticket->fresh(), TaskStatusEnum::IN_PROGRESS, $this->actor, 'second');

        $this->assertSame($base + 2,
            TicketStatusHistory::where('ticket_id', $ticket->id)->count()
        );
    }

    public function test_on_hold_pauses_sla_deadline(): void
    {
        Carbon::setTestNow('2026-05-01 09:00:00');

        $ticket = $this->makeTicket([
            'status'       => TaskStatusEnum::IN_PROGRESS,
            'sla_deadline' => Carbon::parse('2026-05-01 12:00:00'),
        ]);

        // 30 minutes pass, then go on_hold
        Carbon::setTestNow('2026-05-01 09:30:00');
        $this->service->transition($ticket, TaskStatusEnum::ON_HOLD, $this->actor);

        // Spend 60 minutes on hold
        Carbon::setTestNow('2026-05-01 10:30:00');
        $this->service->transition($ticket->fresh(), TaskStatusEnum::IN_PROGRESS, $this->actor);

        $ticket->refresh();
        // Original deadline 12:00 + 60 minutes on_hold = 13:00
        $this->assertSame('2026-05-01 13:00:00', $ticket->sla_deadline->toDateTimeString());
        $this->assertSame(60, (int) $ticket->total_on_hold_minutes);

        Carbon::setTestNow();
    }

    public function test_assigned_resolved_closed_timestamps_set_correctly(): void
    {
        $ticket = $this->makeTicket();

        $this->service->transition($ticket, TaskStatusEnum::ASSIGNED, $this->actor);
        $this->assertNotNull($ticket->fresh()->assigned_at);

        $this->service->transition($ticket->fresh(), TaskStatusEnum::IN_PROGRESS, $this->actor);
        $this->service->transition($ticket->fresh(), TaskStatusEnum::RESOLVED, $this->actor);
        $this->assertNotNull($ticket->fresh()->resolved_at);

        $this->service->transition($ticket->fresh(), TaskStatusEnum::CLOSED, $this->actor);
        $this->assertNotNull($ticket->fresh()->closed_at);
        $this->assertSame($this->actor->id, $ticket->fresh()->closed_by);
    }
}
