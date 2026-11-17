<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\WorkCalendar;
use App\Services\WorkingTimeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-11-16 is a Monday. Office calendar: Mon–Fri 08:00–18:00.
 */
class WorkingTimeCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private WorkingTimeCalculator $calculator;

    private WorkCalendar $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new WorkingTimeCalculator;
        $this->office = WorkCalendar::factory()->create(); // Mon–Fri 08:00–18:00, observes holidays
    }

    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment);
    }

    public function test_without_a_calendar_time_is_plain_clock_time(): void
    {
        $due = $this->calculator->addWorkingMinutes($this->at('2026-11-21 23:00'), 120, null);

        $this->assertEquals($this->at('2026-11-22 01:00'), $due);
        $this->assertSame(120, $this->calculator->workingMinutesBetween($this->at('2026-11-21 23:00'), $due, null));
    }

    public function test_minutes_inside_one_working_day(): void
    {
        $due = $this->calculator->addWorkingMinutes($this->at('2026-11-16 09:00'), 120, $this->office);

        $this->assertEquals($this->at('2026-11-16 11:00'), $due);
    }

    public function test_time_rolls_over_to_the_next_working_morning(): void
    {
        // 17:00 Mon + 120 min: 60 min on Monday, 60 min from 08:00 Tuesday.
        $due = $this->calculator->addWorkingMinutes($this->at('2026-11-16 17:00'), 120, $this->office);

        $this->assertEquals($this->at('2026-11-17 09:00'), $due);
    }

    public function test_ticket_opened_at_night_starts_counting_at_opening_time(): void
    {
        $due = $this->calculator->addWorkingMinutes($this->at('2026-11-16 22:30'), 30, $this->office);

        $this->assertEquals($this->at('2026-11-17 08:30'), $due);
    }

    public function test_weekend_is_skipped(): void
    {
        // Friday 17:30 + 60 min: 30 min Friday, 30 min Monday morning.
        $due = $this->calculator->addWorkingMinutes($this->at('2026-11-20 17:30'), 60, $this->office);

        $this->assertEquals($this->at('2026-11-23 08:30'), $due);
    }

    public function test_observed_holiday_is_skipped(): void
    {
        Holiday::factory()->create(['date' => '2026-11-17']);

        $due = $this->calculator->addWorkingMinutes($this->at('2026-11-16 17:00'), 120, $this->office);

        $this->assertEquals($this->at('2026-11-18 09:00'), $due);
    }

    public function test_holiday_is_ignored_when_the_calendar_does_not_observe_it(): void
    {
        Holiday::factory()->create(['date' => '2026-11-17']);
        $this->office->update(['observes_holidays' => false]);

        $due = $this->calculator->addWorkingMinutes($this->at('2026-11-16 17:00'), 120, $this->office);

        $this->assertEquals($this->at('2026-11-17 09:00'), $due);
    }

    public function test_round_the_clock_calendar_that_observes_holidays_skips_the_holiday(): void
    {
        $allDay = WorkCalendar::factory()->aroundTheClock()->create(['observes_holidays' => true]);
        Holiday::factory()->create(['date' => '2026-11-17']);

        $due = $this->calculator->addWorkingMinutes($this->at('2026-11-16 23:00'), 120, $allDay);

        $this->assertEquals($this->at('2026-11-18 01:00'), $due);
    }

    public function test_working_minutes_between_count_only_open_hours(): void
    {
        // Mon 17:00 → Tue 09:00 = 60 + 60 working minutes.
        $this->assertSame(120, $this->calculator->workingMinutesBetween(
            $this->at('2026-11-16 17:00'), $this->at('2026-11-17 09:00'), $this->office,
        ));

        // Whole weekend = 0.
        $this->assertSame(0, $this->calculator->workingMinutesBetween(
            $this->at('2026-11-21 00:00'), $this->at('2026-11-23 08:00'), $this->office,
        ));

        // Reversed range = 0.
        $this->assertSame(0, $this->calculator->workingMinutesBetween(
            $this->at('2026-11-17 09:00'), $this->at('2026-11-16 17:00'), $this->office,
        ));
    }

    public function test_adding_then_measuring_round_trips(): void
    {
        $start = $this->at('2026-11-19 16:45');

        foreach ([1, 75, 600, 1440, 5000] as $minutes) {
            $due = $this->calculator->addWorkingMinutes($start, $minutes, $this->office);
            $this->assertSame($minutes, $this->calculator->workingMinutesBetween($start, $due, $this->office), "{$minutes} min");
        }
    }

    public function test_calendar_without_any_open_day_fails_loudly(): void
    {
        $never = WorkCalendar::factory()->create(['working_hours' => []]);

        $this->expectException(\RuntimeException::class);
        $this->calculator->addWorkingMinutes($this->at('2026-11-16 09:00'), 10, $never);
    }
}
