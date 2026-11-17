<?php

namespace Database\Factories;

use App\Enums\TaskPriorityEnum;
use App\Models\Employee;
use App\Models\Team;
use App\Models\TicketType;
use App\Models\WorkCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_type_id' => TicketType::factory(),
            'name' => $this->faker->unique()->words(2, true).' Ekibi',
            'description' => null,
            'supervisor_employee_id' => Employee::factory(),
            'work_calendar_id' => null,
            'allow_self_assign' => true,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Team $team): void {
            if ($team->slaPolicies()->exists()) {
                return;
            }

            foreach (TaskPriorityEnum::cases() as $priority) {
                [$response, $resolution] = Team::DEFAULT_SLA_MINUTES[$priority->value];

                $team->slaPolicies()->create([
                    'priority' => $priority,
                    'response_minutes' => $response,
                    'resolution_minutes' => $resolution,
                    'success_threshold' => 80,
                ]);
            }
        });
    }

    public function withCalendar(?WorkCalendar $calendar = null): static
    {
        return $this->state(fn (): array => [
            'work_calendar_id' => $calendar?->id ?? WorkCalendar::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
