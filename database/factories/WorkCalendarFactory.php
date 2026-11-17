<?php

namespace Database\Factories;

use App\Models\WorkCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkCalendar>
 */
class WorkCalendarFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Hafta içi 08:00-18:00 '.$this->faker->unique()->numberBetween(1, 9999),
            'is_24_7' => false,
            'working_hours' => collect(range(1, 5))
                ->mapWithKeys(fn (int $day): array => [(string) $day => ['start' => '08:00', 'end' => '18:00']])
                ->all(),
            'observes_holidays' => true,
            'is_active' => true,
        ];
    }

    public function aroundTheClock(): static
    {
        return $this->state(fn (): array => [
            'name' => '7/24 '.$this->faker->unique()->numberBetween(1, 9999),
            'is_24_7' => true,
            'working_hours' => null,
        ]);
    }
}
