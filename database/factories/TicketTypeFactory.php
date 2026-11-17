<?php

namespace Database\Factories;

use App\Enums\FormFieldTypeEnum;
use App\Enums\LocationRequirementEnum;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->randomElement([
                'Arıza', 'IT Destek', 'Elektrik', 'Mekanik', 'Temizlik', 'Satın Alma', 'Bina Bakım',
            ]).' '.$this->faker->unique()->numberBetween(1, 9999),
            'description' => $this->faker->sentence(),
            'icon' => 'heroicon-o-wrench-screwdriver',
            'color' => 'primary',
            'location_requirement' => LocationRequirementEnum::Optional,
            'form_fields' => [],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function withFields(): static
    {
        return $this->state(fn (): array => [
            'form_fields' => [
                [
                    'key' => 'asset_no',
                    'label' => 'Demirbaş No',
                    'type' => FormFieldTypeEnum::Text->value,
                    'required' => true,
                    'options' => [],
                    'help' => null,
                ],
                [
                    'key' => 'device_kind',
                    'label' => 'Cihaz Türü',
                    'type' => FormFieldTypeEnum::Select->value,
                    'required' => false,
                    'options' => ['Bilgisayar', 'Yazıcı', 'Telefon'],
                    'help' => null,
                ],
            ],
        ]);
    }
}
