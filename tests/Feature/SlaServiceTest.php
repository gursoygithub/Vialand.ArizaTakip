<?php

namespace Tests\Feature;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Models\Area;
use App\Models\SlaPolicy;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\SlaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaServiceTest extends TestCase
{
    use RefreshDatabase;

    private SlaService $slaService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slaService = app(SlaService::class);
        // Authenticate so Model::booted() hooks that read auth()->id() don't write null
        $this->actingAs(User::factory()->create());
    }

    public function test_resolve_policy_specific_match(): void
    {
        $area    = Area::factory()->create(['name' => 'Area 1', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $policy = SlaPolicy::factory()->create([
            'area_id'          => $area->id,
            'sub_area_id'      => $subArea->id,
            'unit_id'          => $unit->id,
            'priority'         => TaskPriorityEnum::High->value,
            'deadline_minutes' => 120,
        ]);

        $resolved = $this->slaService->resolvePolicy(
            $area->id,
            $subArea->id,
            $unit->id,
            TaskPriorityEnum::High->value
        );

        $this->assertNotNull($resolved);
        $this->assertEquals($policy->id, $resolved->id);
    }

    public function test_resolve_policy_fallback_without_sub_area(): void
    {
        $area    = Area::factory()->create(['name' => 'Area F', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $fallbackPolicy = SlaPolicy::factory()->create([
            'area_id'          => $area->id,
            'sub_area_id'      => $subArea->id,
            'unit_id'          => $unit->id,
            'priority'         => TaskPriorityEnum::Medium->value,
            'deadline_minutes' => 240,
        ]);

        // Caller does not know the sub_area; resolver falls back to area+unit+priority
        $resolved = $this->slaService->resolvePolicy(
            $area->id,
            null,
            $unit->id,
            TaskPriorityEnum::Medium->value
        );

        $this->assertNotNull($resolved);
        $this->assertEquals($fallbackPolicy->id, $resolved->id);
    }

    public function test_calculate_deadline_adds_minutes(): void
    {
        Carbon::setTestNow('2026-01-01 08:00:00');

        $area    = Area::factory()->create(['name' => 'D', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $policy = SlaPolicy::factory()->create([
            'area_id'          => $area->id,
            'sub_area_id'      => $subArea->id,
            'unit_id'          => $unit->id,
            'priority'         => TaskPriorityEnum::Low->value,
            'deadline_minutes' => 180,
        ]);

        $deadline = $this->slaService->calculateDeadline($policy, now());

        $this->assertEquals('2026-01-01 11:00:00', $deadline->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_check_breach_returns_true_when_deadline_passed(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');

        $user   = User::factory()->create();
        $area   = Area::factory()->create(['name' => 'B', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit   = Unit::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $subArea->id,
            'unit_id'      => $unit->id,
            'status'       => TaskStatusEnum::OPEN,
            'sla_deadline' => now()->subHour(),
            'created_by'   => $user->id,
        ]);

        $this->assertTrue($this->slaService->checkBreach($ticket));

        Carbon::setTestNow();
    }

    public function test_check_breach_returns_false_when_deadline_not_passed(): void
    {
        $user   = User::factory()->create();
        $area   = Area::factory()->create(['name' => 'C', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit   = Unit::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $subArea->id,
            'unit_id'      => $unit->id,
            'status'       => TaskStatusEnum::OPEN,
            'sla_deadline' => now()->addHour(),
            'created_by'   => $user->id,
        ]);

        $this->assertFalse($this->slaService->checkBreach($ticket));
    }

    public function test_check_breach_returns_false_for_closed_ticket(): void
    {
        $user   = User::factory()->create();
        $area   = Area::factory()->create(['name' => 'E', 'status' => \App\Enums\ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit   = Unit::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $subArea->id,
            'unit_id'      => $unit->id,
            'status'       => TaskStatusEnum::CLOSED,
            'sla_deadline' => now()->subHour(),
            'created_by'   => $user->id,
        ]);

        $this->assertFalse($this->slaService->checkBreach($ticket));
    }
}
