<?php

namespace Database\Factories;

use App\Models\ShippingBillingEntry;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingBillingEntry> */
class ShippingBillingEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'account_fingerprint' => hash('sha256', 'test-account'), 'source_account' => 'test-carrier', 'transaction_id' => fake()->unique()->uuid(), 'shipment_id' => '123', 'order_id' => '42', 'order_number' => '1001', 'type' => 'label_charge', 'amount' => '10.100000', 'currency' => 'USD', 'posted_at' => '2026-10-01', 'statement_reference' => 'Test statement', 'file_hash' => hash('sha256', 'test-file'), 'payload_hash' => hash('sha256', 'test-payload')];
    }

    public function forAccount(Store $store): static
    {
        return $this->for($store)->state(['account_fingerprint' => ShippingBillingEntry::accountFingerprint($store)]);
    }
}
