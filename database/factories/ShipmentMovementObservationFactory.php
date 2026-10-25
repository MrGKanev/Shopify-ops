<?php

namespace Database\Factories;

use App\Models\ShipmentMovementObservation;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShipmentMovementObservation> */
class ShipmentMovementObservationFactory extends Factory
{
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'account_fingerprint' => hash('sha256', 'test-account'), 'identity' => hash('sha256', fake()->unique()->uuid()), 'shipment_id' => (string) fake()->unique()->numberBetween(1, 999999), 'order_number' => '1001', 'carrier' => 'ups', 'tracking_number' => 'TEST-TRACK', 'classification' => 'recent_progress', 'checked_at' => now()];
    }

    public function forAccount(Store $store): static
    {
        return $this->for($store)->state(['account_fingerprint' => ShipmentMovementObservation::accountFingerprint($store)]);
    }
}
