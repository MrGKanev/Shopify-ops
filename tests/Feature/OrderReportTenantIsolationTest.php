<?php

namespace Tests\Feature;

use App\Application\Orders\FilterStoreShipStationOrders;
use App\Application\Reports\RunItemMismatchReport;
use App\Application\Reports\RunOrphanOrderReport;
use App\Application\Reports\RunRefundTrackerReport;
use App\Application\Reports\RunShippedUnfulfilledReport;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ShipStationFixtures;
use Tests\Support\ShopifyFixtures;
use Tests\TestCase;

class OrderReportTenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_orphan_report_never_exposes_another_store_customer(): void
    {
        $store = Store::factory()->create(['store_number' => '42']);
        $this->bindOrders([
            $this->order(1, '1001', 42),
            $this->order(2, '9999', 99, ['shipTo' => ['name' => 'Foreign private customer'], 'customerEmail' => 'foreign@example.com']),
        ], []);

        $result = app(RunOrphanOrderReport::class)->handle($store, '2026-10-01', '2026-10-25');

        $this->assertSame(['1001'], array_column($result->rows, 'order_number'));
        $this->assertSame(1, $result->meta['shipStationTotal']);
        $this->assertStringNotContainsString('Foreign private customer', json_encode($result->rows, JSON_THROW_ON_ERROR));
    }

    public function test_orphan_screen_does_not_render_foreign_customer_details_or_counts(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->bindOrders([
            $this->order(1, '1001', 42),
            $this->order(2, '9999', 99, ['shipTo' => ['name' => 'Foreign private customer'], 'customerEmail' => 'foreign@example.com']),
        ], []);

        $response = $this->actingAs($operator)->post('/reports/orphan-orders', ['start_date' => '2026-10-01', 'end_date' => '2026-10-25']);

        $response->assertSeeText('1 ShipStation vs 0 Shopify orders')->assertDontSeeText('Foreign private customer')->assertDontSeeText('foreign@example.com')->assertDontSeeText('9999');
    }

    public function test_foreign_shipped_order_does_not_raise_a_local_fulfillment_sync_finding(): void
    {
        $store = Store::factory()->create(['store_number' => '42']);
        $this->bindOrders([
            $this->order(1, '1001', 42, ['orderStatus' => 'awaiting_shipment']),
            $this->order(2, '1001', 99),
            $this->order(3, '1002', 42),
        ], [$this->shopifyOrder('1001'), $this->shopifyOrder('1002')]);

        $result = app(RunShippedUnfulfilledReport::class)->handle($store, '2026-10-01', '2026-10-25');

        $this->assertSame(['1002'], array_column($result->rows, 'order_number'));
        $this->assertSame(1, $result->scanned);
        $this->assertSame(1, $result->meta['shippedTotal']);
    }

    public function test_foreign_items_with_same_order_number_do_not_create_a_mismatch(): void
    {
        $store = Store::factory()->create(['store_number' => '42']);
        $this->bindOrders([
            $this->order(1, '1001', 42, ['items' => [['sku' => 'SKU-A', 'quantity' => 1]]]),
            $this->order(2, '1001', 99, ['items' => [['sku' => 'FOREIGN-SKU', 'quantity' => 8]]]),
        ], [$this->shopifyOrder('1001')]);

        $result = app(RunItemMismatchReport::class)->handle($store, '2026-10-01', '2026-10-25');

        $this->assertSame([], $result->rows);
        $this->assertSame(1, $result->scanned);
    }

    public function test_foreign_active_order_does_not_override_local_refunded_order_status(): void
    {
        $store = Store::factory()->create(['store_number' => '42']);
        $this->bindOrders([
            $this->order(1, '1001', 42, ['orderStatus' => 'cancelled']),
            $this->order(2, '1001', 99, ['orderStatus' => 'awaiting_shipment']),
            $this->order(3, '1002', 99, ['orderStatus' => 'awaiting_shipment']),
        ], [$this->shopifyOrder('1001'), $this->shopifyOrder('1002')]);

        $result = app(RunRefundTrackerReport::class)->handle($store, '2026-10-01', '2026-10-25');

        $rows = array_column($result->rows, null, 'order_number');
        $this->assertSame('ok', $rows['#1001']['risk']);
        $this->assertSame(['cancelled'], $rows['#1001']['shipstation_statuses']);
        $this->assertSame('missing', $rows['#1002']['risk']);
        $this->assertSame([], $rows['#1002']['shipstation_orders']);
        $this->assertSame(0, $result->meta['active']);
        $this->assertSame(1, $result->meta['missing']);
    }

    #[DataProvider('reportPaths')]
    public function test_unknown_order_ownership_fails_safely_without_exposing_payload(string $path): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->bindOrders([['orderId' => 5, 'orderNumber' => '1001', 'customerEmail' => 'foreign-secret@example.com']], [$this->shopifyOrder('1001')]);

        $response = $this->actingAs($operator)->post($path, ['start_date' => '2026-10-01', 'end_date' => '2026-10-25']);

        $response->assertSeeText('The integration returned an unexpected response. Try again later.')->assertDontSeeText('foreign-secret@example.com');
    }

    public static function reportPaths(): array
    {
        return [
            'orphans' => ['/reports/orphan-orders'],
            'shipped but unfulfilled' => ['/reports/shipped-unfulfilled'],
            'item mismatch' => ['/reports/item-mismatch'],
            'refund tracker' => ['/reports/refund-tracker'],
        ];
    }

    public function test_shopify_only_refund_tracker_does_not_require_a_shipstation_store_number(): void
    {
        $store = Store::factory()->create(['store_number' => '', 'shipstation_api_key' => '', 'shipstation_api_secret' => '']);
        ShipStationFixtures::bindUnconfigured(1);
        $this->mock(ShopifyPayments::class)->shouldReceive('refundTrackerCandidates')->once()->andReturn(ShopifyFixtures::history([$this->shopifyOrder('1001')]));

        $result = app(RunRefundTrackerReport::class)->handle($store, '2026-10-01', '2026-10-25');

        $this->assertFalse($result->meta['hasShipStation']);
        $this->assertSame('missing', $result->rows[0]['risk']);
        $this->assertSame([], $result->rows[0]['shipstation_orders']);
    }

    #[DataProvider('unconfirmedIdentities')]
    public function test_guard_rejects_malformed_ownership_or_owned_order_identity(array $overrides): void
    {
        $store = Store::factory()->make(['store_number' => '42']);
        $order = array_replace($this->order(1, '1001', 42), $overrides);

        $this->expectException(UnexpectedResponse::class);

        app(FilterStoreShipStationOrders::class)->handle($store, [$order]);
    }

    public static function unconfirmedIdentities(): array
    {
        return [
            'missing owner' => [['advancedOptions' => []]],
            'zero owner' => [['advancedOptions' => ['storeId' => 0]]],
            'negative owner' => [['advancedOptions' => ['storeId' => -42]]],
            'float owner' => [['advancedOptions' => ['storeId' => 42.0]]],
            'malformed owner' => [['advancedOptions' => ['storeId' => '42 invalid']]],
            'missing order id' => [['orderId' => null]],
            'zero order id' => [['orderId' => 0]],
            'malformed order id' => [['orderId' => '1 invalid']],
            'empty order number' => [['orderNumber' => '  ']],
            'nonscalar order number' => [['orderNumber' => ['1001']]],
        ];
    }

    public function test_guard_requires_a_verified_configured_store_even_for_an_empty_result(): void
    {
        $store = Store::factory()->make(['store_number' => '']);

        $this->expectException(LogicException::class);

        app(FilterStoreShipStationOrders::class)->handle($store, []);
    }

    public function test_guard_accepts_native_numeric_string_identity_and_skips_confirmed_foreign_rows(): void
    {
        $store = Store::factory()->make(['store_number' => '42']);
        $owned = $this->order(1, '1001', 42, ['orderId' => '1', 'advancedOptions' => ['storeId' => '42']]);

        $result = app(FilterStoreShipStationOrders::class)->handle($store, [
            ['advancedOptions' => ['storeId' => 99]],
            $owned,
        ]);

        $this->assertSame([$owned], $result);
    }

    /** @param list<array<string, mixed>> $orders
     * @param list<array<string, mixed>> $shopify */
    private function bindOrders(array $orders, array $shopify): void
    {
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchAllOrders')->andReturn($orders);
        $this->mock(ShopifyOrders::class)->shouldReceive('itemMismatchCandidates')->andReturn(ShopifyFixtures::history($shopify));
        $this->mock(ShopifyPayments::class)->shouldReceive('refundTrackerCandidates')->andReturn(ShopifyFixtures::history($shopify));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function order(int $id, string $number, int $store, array $overrides = []): array
    {
        return array_replace(['orderId' => $id, 'orderNumber' => $number, 'advancedOptions' => ['storeId' => $store], 'orderStatus' => 'shipped', 'items' => []], $overrides);
    }

    /** @return array<string, mixed> */
    private function shopifyOrder(string $number): array
    {
        return ['id' => $number, 'name' => '#'.$number, 'order_number' => $number, 'financial_status' => 'paid', 'total_price' => 20, 'fulfillment_status' => null, 'line_items' => [['sku' => 'SKU-A', 'quantity' => 1]]];
    }
}
