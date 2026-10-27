<?php

namespace Database\Factories;

use App\Models\CarrierClaimPolicy;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarrierClaimPolicy>
 */
class CarrierClaimPolicyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'provider' => 'UPS', 'account' => null, 'service' => 'Ground', 'description' => 'Claims within 60 days of shipment', 'source_url' => 'https://example.com/terms', 'verified_on' => now()->toDateString()];
    }
}
