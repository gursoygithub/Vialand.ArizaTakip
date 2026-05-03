<?php

namespace Database\Factories;

use App\Enums\TaskPriorityEnum;
use App\Models\Area;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class SlaPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'area_id'          => Area::factory(),
            'sub_area_id'      => null,
            'unit_id'          => Unit::factory(),
            'priority'         => TaskPriorityEnum::Medium->value,
            'deadline_minutes' => $this->faker->randomElement([60, 120, 240, 480, 1440]),
            'success_threshold' => 80,
            'created_by'       => 1,
        ];
    }
}
