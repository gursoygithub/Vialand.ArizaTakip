<?php

namespace Database\Factories;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'       => $this->faker->company(),
            'status'     => ActiveStatusEnum::ACTIVE,
            'created_by' => 1,
        ];
    }
}
