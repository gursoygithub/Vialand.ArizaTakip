<?php

namespace Database\Factories;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

class AreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'       => $this->faker->city(),
            'status'     => ActiveStatusEnum::ACTIVE,
            'created_by' => 1,
        ];
    }
}
