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
}
