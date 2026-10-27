<?php

namespace Database\Factories;

use App\Domain\Orders\PickListBuilder;
use App\Models\PickListTemplate;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickListTemplate>
 */
class PickListTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->unique()->words(2, true),
            'columns' => PickListBuilder::DEFAULT_COLUMNS,
            'sort' => 'location',
            'size' => 'normal',
        ];
    }
}
