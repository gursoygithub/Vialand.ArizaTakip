<?php

namespace Database\Factories;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TaskTypeEnum;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title'       => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'user_id'     => User::factory(),
            'area_id'     => Area::factory(),
            'sub_area_id' => SubArea::factory(),
            'unit_id'     => Unit::factory(),
            'type_id'     => TaskTypeEnum::cases()[array_rand(TaskTypeEnum::cases())],
            'priority'    => TaskPriorityEnum::Medium,
            'status'      => TaskStatusEnum::OPEN,
            'created_by'  => 1,
            'task_date'   => now()->toDateString(),
        ];
    }

    public function withSlaDeadline(int $minutesFromNow = 60): static
    {
        return $this->state(['sla_deadline' => now()->addMinutes($minutesFromNow)]);
    }

    public function breached(): static
    {
        return $this->state([
            'sla_deadline' => now()->subHour(),
            'sla_breached' => false,
        ]);
    }

    public function closed(): static
    {
        return $this->state([
            'status'    => TaskStatusEnum::CLOSED,
            'closed_at' => now(),
        ]);
    }
}
