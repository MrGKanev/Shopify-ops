<?php

namespace Database\Factories;

use App\Models\OperationalField;
use App\Models\OperationalFieldFilter;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalFieldFilter>
 */
class OperationalFieldFilterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'operational_field_id' => fn (array $attributes): int => OperationalField::factory()->create(['store_id' => $attributes['store_id']])->id, 'name' => fake()->unique()->words(2, true), 'condition' => 'missing', 'text' => null];
    }
}
