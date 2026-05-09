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

class SlaEngineTest extends TestCase
{
    use RefreshDatabase;

    private SlaService $sla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->sla = app(SlaService::class);
    }

    public function test_l1_match_area_subarea_unit_priority(): void
    {
        $area    = Area::factory()->create();
        $sub     = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        $exact = SlaPolicy::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $sub->id,
            'unit_id' => $unit->id, 'priority' => TaskPriorityEnum::High->value,
            'deadline_minutes' => 60,
        ]);

        $resolved = $this->sla->resolvePolicy($area->id, $sub->id, $unit->id, TaskPriorityEnum::High->value);

        $this->assertSame($exact->id, $resolved?->id);
    }

    public function test_l1_override_wins_when_default_also_exists(): void
    {
        // When both a location-specific override (L1) and an area-wide default
        // (L2) exist for the same unit+priority, the override must win.
        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $default = SlaPolicy::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => null,
            'unit_id' => $unit->id, 'priority' => TaskPriorityEnum::High->value,
            'deadline_minutes' => 240,
        ]);

        $override = SlaPolicy::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $sub->id,
            'unit_id' => $unit->id, 'priority' => TaskPriorityEnum::High->value,
            'deadline_minutes' => 60,
        ]);

        $resolved = $this->sla->resolvePolicy($area->id, $sub->id, $unit->id, TaskPriorityEnum::High->value);

        $this->assertSame($override->id, $resolved?->id);
        $this->assertNotSame($default->id, $resolved?->id);
    }

    public function test_l2_default_for_unit_matches_when_no_override(): void
    {
        // A row with sub_area_id IS NULL should match when no L1 override
        // exists for the chosen sub_area.
        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $default = SlaPolicy::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => null,
            'unit_id' => $unit->id, 'priority' => TaskPriorityEnum::Medium->value,
            'deadline_minutes' => 180,
        ]);

        // Caller passes a sub_area_id but no L1 row exists for it → L2 hits.
        $resolved = $this->sla->resolvePolicy($area->id, $sub->id, $unit->id, TaskPriorityEnum::Medium->value);

        $this->assertNotNull($resolved);
        $this->assertSame($default->id, $resolved->id);
    }

    public function test_l2_wins_over_l3_when_unit_default_exists(): void
    {
        // L2 is "area + sub_area IS NULL + unit + priority". L3 is "area +
        // priority" (any sub_area / any unit). When both exist, the more
        // specific L2 must win.
        $area      = Area::factory()->create();
        $unit      = Unit::factory()->create();
        $otherUnit = Unit::factory()->create();

        $l3OnDifferentUnit = SlaPolicy::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => null,
            'unit_id' => $otherUnit->id, 'priority' => TaskPriorityEnum::Low->value,
            'deadline_minutes' => 1440,
        ]);

        $l2 = SlaPolicy::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => null,
            'unit_id' => $unit->id, 'priority' => TaskPriorityEnum::Low->value,
            'deadline_minutes' => 480,
        ]);

        $resolved = $this->sla->resolvePolicy($area->id, null, $unit->id, TaskPriorityEnum::Low->value);

        $this->assertSame($l2->id, $resolved?->id);
        $this->assertNotSame($l3OnDifferentUnit->id, $resolved?->id);
    }

    public function test_l3_area_priority_matches_when_no_unit_default(): void
    {
        // No L1, no L2 for the queried unit. L3 must pick the first row
        // matching (area, priority) regardless of unit/sub_area.
        $area      = Area::factory()->create();
        $sub       = SubArea::factory()->create(['area_id' => $area->id]);
        $unit      = Unit::factory()->create();
        $otherUnit = Unit::factory()->create();

        $rowOnOtherUnit = SlaPolicy::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $sub->id,
            'unit_id' => $otherUnit->id, 'priority' => TaskPriorityEnum::Urgent->value,
            'deadline_minutes' => 30,
        ]);

        $resolved = $this->sla->resolvePolicy($area->id, null, $unit->id, TaskPriorityEnum::Urgent->value);

        $this->assertSame($rowOnOtherUnit->id, $resolved?->id);
    }

    public function test_l4_priority_only_global_fallback(): void
    {
        // No row in the queried area at all. Must fall through to a row
        // matching priority only (potentially in a different area).
        $foreignArea = Area::factory()->create();
        $foreignSub  = SubArea::factory()->create(['area_id' => $foreignArea->id]);
        $foreignUnit = Unit::factory()->create();

        $globalRow = SlaPolicy::factory()->create([
            'area_id' => $foreignArea->id, 'sub_area_id' => $foreignSub->id,
            'unit_id' => $foreignUnit->id, 'priority' => TaskPriorityEnum::Low->value,
            'deadline_minutes' => 720,
        ]);

        $emptyArea = Area::factory()->create();
        $someUnit  = Unit::factory()->create();

        $resolved = $this->sla->resolvePolicy($emptyArea->id, null, $someUnit->id, TaskPriorityEnum::Low->value);

        $this->assertSame($globalRow->id, $resolved?->id);
    }

    public function test_no_match_returns_null(): void
    {
        $area = Area::factory()->create();
        $unit = Unit::factory()->create();

        $resolved = $this->sla->resolvePolicy($area->id, null, $unit->id, TaskPriorityEnum::Urgent->value);

        $this->assertNull($resolved);
    }

    public function test_l2_fallback_area_priority(): void
    {
        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();
        $otherUnit = Unit::factory()->create();

        // Policy exists for a DIFFERENT unit in the same area
        $policy = SlaPolicy::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $sub->id,
            'unit_id' => $otherUnit->id, 'priority' => TaskPriorityEnum::Medium->value,
            'deadline_minutes' => 240,
        ]);

        // Ask for the area+priority — should fall back to L2
        $resolved = $this->sla->resolvePolicy($area->id, null, $unit->id, TaskPriorityEnum::Medium->value);

        $this->assertNotNull($resolved);
        $this->assertSame($policy->id, $resolved->id);
    }

    public function test_l3_fallback_priority_only(): void
    {
        $otherArea = Area::factory()->create();
        $otherSub  = SubArea::factory()->create(['area_id' => $otherArea->id]);
        $otherUnit = Unit::factory()->create();

        $policy = SlaPolicy::factory()->create([
            'area_id' => $otherArea->id, 'sub_area_id' => $otherSub->id,
            'unit_id' => $otherUnit->id, 'priority' => TaskPriorityEnum::Low->value,
            'deadline_minutes' => 480,
        ]);

        $someArea = Area::factory()->create();
        $someUnit = Unit::factory()->create();

        // No policy in someArea, but priority Low exists globally → L3 fallback
        $resolved = $this->sla->resolvePolicy($someArea->id, null, $someUnit->id, TaskPriorityEnum::Low->value);

        $this->assertNotNull($resolved);
        $this->assertSame($policy->id, $resolved->id);
    }

    public function test_get_remaining_minutes_negative_when_breached(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $sub->id, 'unit_id' => $unit->id,
            'sla_deadline' => Carbon::parse('2026-05-01 09:00:00'), // 1 hour ago
            'status' => TaskStatusEnum::IN_PROGRESS,
        ]);

        $minutes = $this->sla->getRemainingMinutes($ticket);

        $this->assertNotNull($minutes);
        $this->assertLessThan(0, $minutes);
        $this->assertSame(-60, $minutes);

        Carbon::setTestNow();
    }

    public function test_get_elapsed_percentage_exceeds_one_when_breached(): void
    {
        Carbon::setTestNow('2026-05-01 12:00:00');

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id' => $area->id, 'sub_area_id' => $sub->id, 'unit_id' => $unit->id,
            'created_at'   => Carbon::parse('2026-05-01 09:00:00'),
            'sla_deadline' => Carbon::parse('2026-05-01 11:00:00'), // 2-hour window
            'status'       => TaskStatusEnum::IN_PROGRESS,
        ]);

        $pct = $this->sla->getElapsedPercentage($ticket);

        $this->assertNotNull($pct);
        $this->assertGreaterThan(1.0, $pct);
        // 3 hours elapsed of a 2-hour window → 1.5
        $this->assertEqualsWithDelta(1.5, $pct, 0.01);

        Carbon::setTestNow();
    }

    public function test_get_elapsed_percentage_is_zero_at_creation(): void
    {
        Carbon::setTestNow('2026-05-01 09:00:00');

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        // Ticket created now with a 2-hour window — no time has elapsed yet.
        $ticket = Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $sub->id,
            'unit_id'      => $unit->id,
            'created_at'   => Carbon::parse('2026-05-01 09:00:00'),
            'sla_deadline' => Carbon::parse('2026-05-01 11:00:00'),
            'status'       => TaskStatusEnum::IN_PROGRESS,
        ]);

        $pct = $this->sla->getElapsedPercentage($ticket);

        $this->assertNotNull($pct);
        $this->assertEqualsWithDelta(0.0, $pct, 0.01);

        Carbon::setTestNow();
    }

    public function test_get_elapsed_percentage_is_near_one_at_deadline(): void
    {
        // Set now to just before the deadline (119 of 120 minutes elapsed → ~0.99).
        Carbon::setTestNow('2026-05-01 10:59:00');

        $area = Area::factory()->create();
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->create([
            'area_id'      => $area->id,
            'sub_area_id'  => $sub->id,
            'unit_id'      => $unit->id,
            'created_at'   => Carbon::parse('2026-05-01 09:00:00'),
            'sla_deadline' => Carbon::parse('2026-05-01 11:00:00'), // 2-hour window
            'status'       => TaskStatusEnum::IN_PROGRESS,
        ]);

        $pct = $this->sla->getElapsedPercentage($ticket);

        $this->assertNotNull($pct);
        $this->assertLessThan(1.0, $pct);
        $this->assertGreaterThan(0.9, $pct);

        Carbon::setTestNow();
    }
}
