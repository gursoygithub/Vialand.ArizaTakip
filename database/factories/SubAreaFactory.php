<?php

namespace Database\Factories;

use App\Models\Area;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubAreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'area_id'    => Area::factory(),
            'name'       => $this->faker->streetName(),
            'created_by' => 1,
        ];
    }
}
