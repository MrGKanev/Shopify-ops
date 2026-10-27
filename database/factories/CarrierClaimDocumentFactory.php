<?php

namespace Database\Factories;

use App\Models\CarrierClaim;
use App\Models\CarrierClaimDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CarrierClaimDocument>
 */
class CarrierClaimDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'carrier_claim_id' => CarrierClaim::factory(),
            'store_id' => fn (array $attributes): int => CarrierClaim::findOrFail($attributes['carrier_claim_id'])->store_id,
            'uploaded_by' => null,
            'original_name' => 'proof.pdf',
            'path' => 'carrier-claims/'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ];
    }
}
