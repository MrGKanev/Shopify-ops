<?php

namespace Tests\Feature;

use App\Application\Reports\RunShippingMarginReport;
use App\Application\Reports\RunVoidedShipmentsReport;
use App\Application\Reports\ShippingBillingCosts;
use App\Domain\Reports\ShippingBillingAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\ShippingBillingEntry;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\ShipStationFixtures;
use Tests\Support\ShopifyFixtures;
use Tests\TestCase;

class ShippingBillingReportsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_duplicate_label_charges_do_not_produce_a_complete_imported_contribution(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $store = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($store)->create(['amount' => '10', 'posted_at' => '2026-10-01']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['amount' => '12', 'posted_at' => '2026-10-01']);
        $rows = [['order_number' => '#1001', 'labels' => [['id' => '123', 'order_id' => '42']], 'shopify_contribution' => 50.0, 'contribution' => 37.0]];

        $result = app(ShippingBillingCosts::class)->contribution($store, $rows, 'USD');

        $this->assertNull($result[0]['imported_shipping']);
        $this->assertNull($result[0]['contribution_on_imported_billing']);
        $this->assertSame(37.0, $result[0]['contribution']);
        $this->assertSame('review_required', $result[0]['labels'][0]['imported_billing']['refund_status']);
        $this->assertSame('22.000000', $result[0]['labels'][0]['imported_billing']['observed_net']);
    }

    public function test_exact_totals_keep_currencies_separate_and_ignore_dispute_decisions(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $store = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($store)->create(['amount' => '0.1', 'dispute_status' => 'accepted', 'expected_refund' => '0.1', 'refund_due_at' => '2026-10-20']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['type' => 'adjustment_debit', 'amount' => '0.2']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['shipment_id' => '999', 'currency' => 'EUR', 'amount' => '9']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['type' => 'adjustment_credit', 'amount' => '100', 'excluded' => true]);

        $data = app(ShippingBillingCosts::class)->forShipments($store, ['123', '999']);

        $this->assertFalse($data['truncated']);
        $this->assertSame('0.300000', $data['groups'][0]['observed_net']);
        $this->assertSame('0.100000', $data['groups'][0]['outstanding_refund']);
        $this->assertSame('overdue', $data['groups'][0]['refund_status']);
        $this->assertSame('0', $data['groups'][0]['refunds']);
        $grouped = app(ShippingBillingAnalyzer::class)->grouped($data['groups'], 'carrier');
        $this->assertSame(['USD', 'EUR'], array_column($grouped, 'currency'));
    }

    public function test_partial_and_complete_refunds_require_posted_label_refund_entries(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $store = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($store)->create(['expected_refund' => '10.1', 'refund_due_at' => '2026-11-01']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['type' => 'label_refund', 'amount' => '4']);
        $reader = app(ShippingBillingCosts::class);
        $this->assertSame('partially_credited', $reader->forShipments($store, ['123'])['groups'][0]['refund_status']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['type' => 'label_refund', 'amount' => '6.1']);

        $group = $reader->forShipments($store, ['123'])['groups'][0];

        $this->assertSame('credited', $group['refund_status']);
        $this->assertSame('0.000000', $group['outstanding_refund']);
        $this->assertSame('0.000000', $group['observed_net']);
    }

    public function test_order_account_and_currency_conflicts_never_become_cost_coverage(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $store = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($store)->create();
        ShippingBillingEntry::factory()->create(['shipment_id' => '123', 'amount' => '1000']);
        $reader = app(ShippingBillingCosts::class);
        $data = $reader->forShipments($store, ['123']);
        $this->assertNull($reader->match($data['groups'], '123', 'wrong', '1001', 'USD'));
        $this->assertNull($reader->match($data['groups'], '123', '42', '1001', 'GBP'));
        ShippingBillingEntry::factory()->forAccount($store)->create(['currency' => 'EUR']);
        $this->assertNull($reader->match($reader->forShipments($store, ['123'])['groups'], '123', '42', '1001', 'USD'));
        $store->update(['store_number' => 'changed']);
        $this->assertSame([], $reader->forShipments($store, ['123'])['groups']);
    }

    public function test_contribution_uses_a_separate_imported_basis_without_assuming_missing_labels_are_zero(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $store = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($store)->create(['amount' => '8']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['type' => 'adjustment_debit', 'amount' => '2']);
        $rows = [['order_number' => '#1001', 'labels' => [['id' => '123', 'order_id' => '42']], 'shopify_contribution' => 67.0, 'contribution' => null]];

        $rows = app(ShippingBillingCosts::class)->contribution($store, $rows, 'USD');

        $this->assertSame('10.000000', $rows[0]['imported_shipping']);
        $this->assertSame('57.000000', $rows[0]['contribution_on_imported_billing']);
        $this->assertNull($rows[0]['contribution']);
        $rows[0]['labels'][] = ['id' => 'unimported', 'order_id' => '42'];
        $this->assertNull(app(ShippingBillingCosts::class)->contribution($store, $rows, 'USD')[0]['imported_shipping']);
    }

    public function test_shipping_margin_counts_customer_shipping_once_for_multiple_labels(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $store = Store::factory()->create();
        foreach (['123', '124'] as $id) {
            ShippingBillingEntry::factory()->forAccount($store)->create(['shipment_id' => $id, 'amount' => '15']);
        }
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('fetchShipmentsByDate')->once()->andReturn(array_map(fn (string $id): array => ['shipmentId' => $id, 'orderId' => '42', 'orderNumber' => '1001', 'shipmentCost' => 10, 'insuranceCost' => 0, 'voided' => false, 'shipDate' => '2026-10-01', 'carrierCode' => 'ups'], ['123', '124']));
        $client->shouldReceive('getOrder')->once()->with(42)->andReturn(ShipStationFixtures::lookupOrder(42, '1001', (int) $store->store_number));
        $this->mock(ShopifyOrders::class)->shouldReceive('shippingMarginCandidates')->once()->andReturn(ShopifyFixtures::history([['order_number' => '1001', 'currency' => 'USD', 'shipping_lines' => [['price' => '20']]]]));

        $result = app(RunShippingMarginReport::class)->handle($store, '2026-10-01', '2026-10-23', 5);

        $this->assertCount(1, $result->rows);
        $this->assertSame(30.0, $result->rows[0]['ship_cost']);
        $this->assertSame(20.0, $result->rows[0]['shipping_charged']);
        $this->assertSame(10.0, $result->rows[0]['loss']);
        $this->assertSame('30.000000', $result->rows[0]['imported_cost']);
    }

    public function test_voided_labels_show_unknown_without_a_matching_prepaid_charge_and_explicit_expectation(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $store = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($store)->create(['expected_refund' => '10.1', 'refund_due_at' => '2026-10-20']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['type' => 'label_refund', 'amount' => '4']);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('fetchVoidedShipments')->once()->andReturn([['shipmentId' => 123, 'orderId' => 42, 'orderNumber' => '1001', 'voidDate' => '2026-10-01'], ['shipmentId' => 999, 'orderId' => 42, 'orderNumber' => '1001', 'voidDate' => '2026-10-01']]);
        $client->shouldReceive('getOrder')->once()->with(42)->andReturn(ShipStationFixtures::lookupOrder(42, '1001', (int) $store->store_number));

        $result = app(RunVoidedShipmentsReport::class)->handle($store, '2026-10-01', '2026-10-23');

        $this->assertSame('overdue', $result->rows[0]['refund_billing']['refund_status']);
        $this->assertSame('6.100000', $result->rows[0]['refund_billing']['outstanding_refund']);
        $this->assertNull($result->rows[1]['refund_billing']);
    }

    public function test_adjustment_only_data_and_multiple_base_charges_do_not_establish_a_refund_entitlement(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $store = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($store)->create(['type' => 'adjustment_debit', 'amount' => '2']);
        $reader = app(ShippingBillingCosts::class);
        $group = $reader->forShipments($store, ['123'])['groups'][0];
        $this->assertNull($group['billed_cost']);
        $this->assertNull($group['outstanding_refund']);
        ShippingBillingEntry::factory()->forAccount($store)->count(2)->create(['expected_refund' => '10.1', 'refund_due_at' => '2026-10-20']);

        $group = $reader->forShipments($store, ['123'])['groups'][0];

        $this->assertSame('review_required', $group['refund_status']);
        $this->assertNull($group['expected_refund']);
    }

    public function test_multiple_source_accounts_and_conflicting_label_currency_prevent_contribution_matching(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $store = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($store)->create();
        $reader = app(ShippingBillingCosts::class);
        $rows = [['order_number' => '1001', 'shopify_contribution' => 20.0, 'labels' => [['id' => '123', 'order_id' => '42', 'currency' => 'GBP']]]];
        $this->assertNull($reader->contribution($store, $rows, 'USD')[0]['imported_shipping']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['source_account' => 'other-source']);

        $this->assertNull($reader->match($reader->forShipments($store, ['123'])['groups'], '123', '42', '1001', 'USD'));
    }

    public function test_reversed_refund_reopens_the_recorded_outstanding_amount(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $store = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($store)->create(['expected_refund' => '10.1', 'refund_due_at' => '2026-11-01']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['type' => 'label_refund', 'amount' => '10.1']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['type' => 'label_refund_reversal', 'amount' => '4']);

        $group = app(ShippingBillingCosts::class)->forShipments($store, ['123'])['groups'][0];

        $this->assertSame('6.100000', $group['refunds']);
        $this->assertSame('4.000000', $group['outstanding_refund']);
        $this->assertSame('partially_credited', $group['refund_status']);
        $this->assertSame('4.000000', $group['observed_net']);
    }
}
