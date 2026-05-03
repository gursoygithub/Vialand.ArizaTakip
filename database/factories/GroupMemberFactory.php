<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

class GroupMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id'    => Group::factory(),
            'employee_id' => Employee::factory(),
            'created_by'  => 1,
        ];
    }
}
