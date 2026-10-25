<?php

namespace Database\Seeders;

use App\Models\ShippingBillingEntry;
use App\Models\Store;
use Illuminate\Database\Seeder;
use LogicException;

class ShippingBillingEntrySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Example financial postings may only be seeded in the testing environment. Import real statements in other environments.');
        }
        $store = Store::factory()->create(['label' => 'Billing examples (testing)', 'store_number' => '12']);
        ShippingBillingEntry::factory()->forAccount($store)->createMany([
            ['type' => 'label_charge', 'amount' => '10.1', 'expected_refund' => '10.1', 'refund_due_at' => '2026-10-20'],
            ['type' => 'adjustment_debit', 'amount' => '0.2'],
            ['type' => 'adjustment_credit', 'amount' => '0.1'],
            ['type' => 'label_refund', 'amount' => '4.0'],
        ]);
    }
}
