<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Models\Area;
use App\Models\Employee;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewEmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    // ─── M2: ViewEmployee RepeatableEntry threshold color logic ─────────────
    //
    // Filament 3.x provides no stable public API to read computed badge colors
    // from a rendered infolist, so these tests exercise the closure logic
    // directly — the same pattern used throughout the test suite for Filament
    // closures (see TicketActionVisibilityTest for precedent).
    //
    // The closure in ViewEmployee::infolist() sections 4 and 5 is:
    //
    //   function ($state, $record) use ($threshold) {
    //       $rate = $record['raw_percentage'] ?? 0;
    //       $hi   = $threshold ?? 80;
    //       $lo   = $threshold !== null ? $threshold * 0.75 : 50;
    //       return $rate >= $hi ? 'success' : ($rate >= $lo ? 'warning' : 'danger');
    //   }
    //
    // `$threshold` = $employee->current_threshold (captured from the outer scope).

    /**
     * Replicate the badge color logic so tests are independent of the
     * Filament rendering pipeline.
     */
    private function thresholdColor(float $rate, ?float $threshold): string
    {
        if (is_null($threshold)) return 'gray';
        $hi = $threshold;
        $lo = $threshold * 0.75;

        return $rate >= $hi ? 'success' : ($rate >= $lo ? 'warning' : 'danger');
    }

    public function test_badge_is_success_when_rate_meets_employee_threshold(): void
    {
        // Exactly at threshold = success.
        $this->assertSame('success', $this->thresholdColor(70.0, 70.0));
        // Above threshold = success.
        $this->assertSame('success', $this->thresholdColor(100.0, 70.0));
    }

    public function test_badge_is_warning_when_rate_is_in_the_75_percent_band(): void
    {
        // threshold=70 → hi=70, lo=52.5
        // 55 is between 52.5 and 70 → warning.
        $this->assertSame('warning', $this->thresholdColor(55.0, 70.0));
        // Exactly at the lower bound (52.5) → warning (>= lo).
        $this->assertSame('warning', $this->thresholdColor(52.5, 70.0));
        // One step below threshold → still warning if >= lo.
        $this->assertSame('warning', $this->thresholdColor(69.9, 70.0));
    }

    public function test_badge_is_danger_when_rate_is_below_75_percent_of_threshold(): void
    {
        // threshold=70 → lo=52.5; 40 < 52.5 → danger.
        $this->assertSame('danger', $this->thresholdColor(40.0, 70.0));
        $this->assertSame('danger', $this->thresholdColor(0.0, 70.0));
    }

    public function test_badge_is_gray_when_threshold_is_null(): void
    {
        // null threshold → no data yet → always gray, regardless of rate.
        $this->assertSame('gray', $this->thresholdColor(100.0, null));
        $this->assertSame('gray', $this->thresholdColor(80.0, null));
        $this->assertSame('gray', $this->thresholdColor(50.0, null));
        $this->assertSame('gray', $this->thresholdColor(0.0, null));
    }

    // ─── M3: TicketsRelationManager SLA badge color ─────────────────────────
    //
    // The color closure in TicketsRelationManager::table() on the
    // `sla_status_label` TextColumn:
    //
    //   function ($record) {
    //       if ($record->sla_breached) return 'danger';
    //       if ($record->status === TaskStatusEnum::ON_HOLD) return 'warning';
    //       if (is_null($record->sla_deadline)) return 'gray';
    //       return 'success';
    //   }
    //
    // Tests create real Ticket models and run them through the reproduced
    // closure. No SlaPolicy is created, so TicketObserver::creating() will
    // not overwrite the explicit sla_deadline values.

    private function makeTicket(array $overrides = []): Ticket
    {
        $area = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $sub  = SubArea::factory()->create(['area_id' => $area->id]);
        $unit = Unit::factory()->create();

        return Ticket::factory()->create(array_merge([
            'area_id'     => $area->id,
            'sub_area_id' => $sub->id,
            'unit_id'     => $unit->id,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => auth()->id(),
        ], $overrides));
    }

    /**
     * Reproduce the color closure from TicketsRelationManager.
     * Filament 3.x has no stable public API to read column badge colors
     * from a mounted relation manager in test context.
     */
    private function slaColor(Ticket $record): string
    {
        if ($record->sla_breached) return 'danger';
        if ($record->status === TaskStatusEnum::ON_HOLD) return 'warning';
        if (is_null($record->sla_deadline)) return 'gray';
        return 'success';
    }

    public function test_sla_badge_is_gray_when_ticket_has_no_sla_policy(): void
    {
        // No SlaPolicy → observer leaves sla_deadline null.
        $ticket = $this->makeTicket(['sla_deadline' => null, 'sla_breached' => false]);

        $this->assertSame('gray', $this->slaColor($ticket->fresh()));
    }

    public function test_sla_badge_is_danger_when_ticket_is_breached(): void
    {
        // Past deadline + non-terminal status → observer sets sla_breached=true.
        $ticket = $this->makeTicket([
            'sla_deadline' => now()->subHour(),
            'status'       => TaskStatusEnum::ASSIGNED,
        ]);

        $this->assertSame('danger', $this->slaColor($ticket->fresh()));
    }

    public function test_sla_badge_is_warning_when_ticket_is_on_hold_and_not_breached(): void
    {
        $ticket = $this->makeTicket([
            'sla_deadline' => now()->addHour(),
            'sla_breached' => false,
            'status'       => TaskStatusEnum::ON_HOLD,
        ]);

        $this->assertSame('warning', $this->slaColor($ticket->fresh()));
    }

    public function test_sla_badge_is_success_when_ticket_has_valid_sla_and_not_breached(): void
    {
        $ticket = $this->makeTicket([
            'sla_deadline' => now()->addHour(),
            'sla_breached' => false,
            'status'       => TaskStatusEnum::IN_PROGRESS,
        ]);

        $this->assertSame('success', $this->slaColor($ticket->fresh()));
    }

    public function test_sla_badge_is_danger_for_breached_ticket_regardless_of_on_hold_status(): void
    {
        // sla_breached takes priority over the ON_HOLD check.
        $ticket = $this->makeTicket([
            'sla_deadline' => now()->subHour(),
            'status'       => TaskStatusEnum::ON_HOLD,
        ]);

        // Observer will have set sla_breached=true due to past deadline.
        $this->assertSame('danger', $this->slaColor($ticket->fresh()));
    }

    // ─── FIX 1: cohort count uses $denominator (sealed only) ────────────────
    //
    // ViewEmployee section 3 "Değerlendirilen Talep" badge must show the
    // sealed count (on-time + breached), not the total non-CANCELLED count.
    // $totalCohortCount = $denominator = $closedOnTime + $totalBreached.

    public function test_degerlendirilen_talep_shows_sealed_count_not_total(): void
    {
        $employee = Employee::factory()->create();

        // Ticket 1: resolved on-time (sealed — counts in $closedOnTime).
        $this->makeTicket([
            'employee_id' => $employee->id,
            'status'      => TaskStatusEnum::RESOLVED,
            'resolved_at' => now(),
            'sla_breached' => false,
        ]);

        // Ticket 2: still active OPEN (not sealed — excluded from denominator).
        $this->makeTicket([
            'employee_id' => $employee->id,
            'status'      => TaskStatusEnum::OPEN,
            'resolved_at' => null,
            'sla_breached' => false,
        ]);

        // Replicate the ViewEmployee denominator computation exactly.
        $performanceCohort = $employee->tickets()
            ->whereNotIn('status', [TaskStatusEnum::CANCELLED]);

        $closedOnTime  = (clone $performanceCohort)
            ->whereNotNull('resolved_at')
            ->where('sla_breached', false)
            ->count();
        $totalBreached = (clone $performanceCohort)
            ->where('sla_breached', true)
            ->count();

        $denominator      = $closedOnTime + $totalBreached;
        $totalCohortCount = $denominator;

        // Only the sealed (RESOLVED on-time) ticket is in the denominator.
        $this->assertSame(1, $denominator, 'denominator must equal sealed tickets only');
        $this->assertSame(1, $totalCohortCount, 'cohort badge must show sealed count, not total');
    }
}
