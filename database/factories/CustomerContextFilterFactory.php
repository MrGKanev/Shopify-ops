<?php

namespace Database\Factories;

use App\Domain\Orders\CustomerFulfillmentContext;
use App\Models\CustomerContextFilter;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerContextFilter>
 */
class CustomerContextFilterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'name' => fake()->unique()->words(2, true), 'rule' => 'all', 'vip_at' => 5, 'milestones' => CustomerFulfillmentContext::MILESTONES];
    }
}
