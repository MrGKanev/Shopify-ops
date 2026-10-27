<?php

namespace Database\Factories;

use App\Models\CarrierClaim;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarrierClaim>
 */
class CarrierClaimFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = 'UPS';
        $reference = fake()->unique()->bothify('CLM-######');

        return [
            'store_id' => Store::factory(),
            'provider' => $provider,
            'account' => null,
            'external_reference' => $reference,
            'case_key' => CarrierClaim::caseKey($provider, $reference),
            'status' => 'draft',
            'currency' => 'USD',
        ];
    }
}
