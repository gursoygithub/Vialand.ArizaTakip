<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\WorkCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * SLA arithmetic in a team's working time.
 *
 * A NULL calendar, or a 24/7 calendar that ignores holidays, is continuous
 * clock time. Otherwise only the calendar's opening hours count, and public
 * holidays are skipped when the calendar observes them.
 */
class WorkingTimeCalculator
{
    /**
     * Safety stop for calendars with no working time at all.
     */
    private const MAX_DAYS = 400;

    /**
     * @var array<string, true>|null
     */
    private ?array $holidayDates = null;

    /**
     * The moment $minutes of working time have passed after $from.
     */
    public function addWorkingMinutes(CarbonInterface $from, int $minutes, ?WorkCalendar $calendar): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from);

        if ($minutes <= 0) {
            return $from;
        }

        if ($this->isContinuous($calendar)) {
            return $from->addMinutes($minutes);
        }

        $remainingSeconds = $minutes * 60;
        $cursor = $from;

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $day = $cursor->startOfDay();
            $window = $this->windowFor($calendar, $day);

            if ($window !== null) {
                [$open, $close] = $window;
                $start = $cursor->greaterThan($open) ? $cursor : $open;

                if ($start->lessThan($close)) {
                    $available = $close->getTimestamp() - $start->getTimestamp();

                    if ($available >= $remainingSeconds) {
                        return $start->addSeconds($remainingSeconds);
                    }

                    $remainingSeconds -= $available;
                }
            }

            $cursor = $day->addDay();
        }

        throw new RuntimeException("Work calendar #{$calendar->id} has no working time within ".self::MAX_DAYS.' days.');
    }

    /**
     * Whole working minutes between two moments (0 when $to is not after $from).
     */
    public function workingMinutesBetween(CarbonInterface $from, CarbonInterface $to, ?WorkCalendar $calendar): int
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);

        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        if ($this->isContinuous($calendar)) {
            return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
        }

        $seconds = 0;
        $day = $from->startOfDay();
        $lastDay = $to->startOfDay();

        for ($i = 0; $i <= self::MAX_DAYS && $day->lessThanOrEqualTo($lastDay); $i++) {
            $window = $this->windowFor($calendar, $day);

            if ($window !== null) {
                [$open, $close] = $window;
                $start = $from->greaterThan($open) ? $from : $open;
                $end = $to->lessThan($close) ? $to : $close;

                if ($start->lessThan($end)) {
                    $seconds += $end->getTimestamp() - $start->getTimestamp();
                }
            }

            $day = $day->addDay();
        }

        return intdiv($seconds, 60);
    }

    public function isContinuous(?WorkCalendar $calendar): bool
    {
        return $calendar === null || ($calendar->is_24_7 && ! $calendar->observes_holidays);
    }

    /**
     * Opening and closing moment of $day, or null when closed (closed weekday
     * or an observed holiday).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function windowFor(WorkCalendar $calendar, CarbonImmutable $day): ?array
    {
        if ($calendar->observes_holidays && $this->isHoliday($day)) {
            return null;
        }

        $hours = $calendar->hoursFor($day->isoWeekday());

        if ($hours === null) {
            return null;
        }

        $open = $day->setTimeFromTimeString($hours['start']);
        $close = $hours['end'] === '24:00'
            ? $day->addDay()
            : $day->setTimeFromTimeString($hours['end']);

        return $close->greaterThan($open) ? [$open, $close] : null;
    }

    private function isHoliday(CarbonImmutable $day): bool
    {
        $this->holidayDates ??= Holiday::query()
            ->pluck('date')
            ->mapWithKeys(fn ($date): array => [CarbonImmutable::parse($date)->toDateString() => true])
            ->all();

        return isset($this->holidayDates[$day->toDateString()]);
    }

    /**
     * Forget the cached holiday list (after holidays change, or in tests).
     */
    public function flushHolidays(): void
    {
        $this->holidayDates = null;
    }
}
