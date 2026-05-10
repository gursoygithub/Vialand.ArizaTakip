<?php

namespace App\Support;

class DurationFormatter
{
    public static function minutes(int $minutes): string
    {
        if ($minutes <= 0) return '0dk';
        if ($minutes < 60) return $minutes . 'dk';

        $days        = intdiv($minutes, 1440);
        $remAfterDays = $minutes % 1440;
        $hours       = intdiv($remAfterDays, 60);
        $mins        = $remAfterDays % 60;

        if ($days > 0) {
            return $hours > 0 ? "{$days}g {$hours}sa" : "{$days}g";
        }
        return $mins > 0 ? "{$hours}sa {$mins}dk" : "{$hours}sa";
    }
}
