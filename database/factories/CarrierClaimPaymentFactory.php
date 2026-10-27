<?php

namespace Database\Factories;

use App\Models\CarrierClaim;
use App\Models\CarrierClaimPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarrierClaimPayment>
 */
class CarrierClaimPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'carrier_claim_id' => CarrierClaim::factory(),
            'store_id' => fn (array $attributes): mixed => CarrierClaim::query()->findOrFail($attributes['carrier_claim_id'])->store_id,
            'kind' => 'postage',
            'amount' => '10.000000',
            'currency' => 'USD',
            'received_on' => now()->toDateString(),
            'source_note' => 'Carrier portal',
            'ledger_reference' => null,
        ];
    }
}
