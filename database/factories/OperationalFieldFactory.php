<?php

namespace Database\Factories;

use App\Models\OperationalField;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalField>
 */
class OperationalFieldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'alias' => fake()->unique()->words(2, true),
            'source_type' => OperationalField::SOURCE_METAFIELD,
            'source' => 'custom.'.fake()->unique()->slug(1),
            'required' => false,
        ];
    }

    public function customField(int $number = 1): static
    {
        return $this->state(['source_type' => OperationalField::SOURCE_CUSTOM_FIELD, 'source' => 'customField'.$number]);
    }
}
