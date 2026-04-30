<?php

namespace Database\Factories;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => 'E' . $this->faker->unique()->numerify('######'),
            'tc_no'       => $this->faker->unique()->numerify('###########'),
            'name'        => $this->faker->name(),
            'email'       => $this->faker->unique()->safeEmail(),
            'phone'       => $this->faker->phoneNumber(),
            'status'      => ActiveStatusEnum::ACTIVE,
            'created_by'  => 1,
        ];
    }
}
