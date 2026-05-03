<?php

namespace Database\Factories;

use App\Enums\ActiveStatusEnum;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class GroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'        => $this->faker->company(),
            'company_id'  => Company::factory(),
            'area_id'     => Area::factory(),
            'unit_id'     => Unit::factory(),
            'employee_id' => Employee::factory(),
            'status'      => ActiveStatusEnum::ACTIVE,
            'created_by'  => 1,
        ];
    }
}
