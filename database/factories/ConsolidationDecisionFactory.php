<?php

namespace Database\Factories;

use App\Models\ConsolidationDecision;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsolidationDecision>
 */
class ConsolidationDecisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'order_number' => (string) fake()->unique()->numberBetween(1000, 99999), 'decision' => 'excluded', 'user_id' => null];
    }
}
