<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class PreShipmentDriftTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param list<array<string, mixed>> $lines */
    private function fulfillmentOrder(string $status, array $lines, bool $moreLines = false): array
    {
        return ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => $status, 'lineItems' => ['pageInfo' => ['hasNextPage' => $moreLines], 'nodes' => $lines]];
    }

    private function line(?string $sku, int $remaining, bool $ships = true): array
    {
        return ['id' => 'gid://shopify/FulfillmentOrderLineItem/1', 'sku' => $sku, 'remainingQuantity' => $remaining, 'totalQuantity' => 5, 'requiresShipping' => $ships];
    }

    private function shopifyOrder(string $name, array $fulfillmentOrders, bool $moreFulfillmentOrders = false, ?string $cancelledAt = null): array
    {
        return ['node' => ['id' => 'gid://shopify/Order/'.$name, 'name' => '#'.$name, 'cancelledAt' => $cancelledAt, 'shippingAddress' => ['name' => 'Jane Doe', 'address1' => '1 Main St', 'address2' => 'Apt 4', 'city' => 'Austin', 'provinceCode' => 'TX', 'zip' => '78701', 'countryCodeV2' => 'US', 'phone' => null, 'company' => null], 'fulfillmentOrders' => ['pageInfo' => ['hasNextPage' => $moreFulfillmentOrders], 'nodes' => $fulfillmentOrders]]];
    }

    private function fakeShopify(array $edges, bool $hasNext = false): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => $hasNext], 'edges' => $edges]]])]);
    }

    private function client(): ShopifyAdminClient
    {
        return app(ShopifyAdminClient::class);
    }

    public function test_client_sums_only_open_shippable_remaining_quantities_per_sku(): void
    {
        $this->fakeShopify([
            $this->shopifyOrder('1001', [
                $this->fulfillmentOrder('OPEN', [$this->line(' TEE ', 2), $this->line('tee', 1), $this->line('gift', 3, false), $this->line('done', 0), $this->line(null, 1)]),
                $this->fulfillmentOrder('CLOSED', [$this->line('old', 4)]),
                $this->fulfillmentOrder('ON_HOLD', [$this->line('MUG', 1)]),
            ]),
        ]);

        $result = $this->client()->preShipmentRemainingItems(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), ['#1001']);

        $order = $result['1001'][0];
        $this->assertSame(['mug' => 1, 'tee' => 3], $order['remaining']);
        $this->assertTrue($order['complete']);
        $this->assertTrue($order['has_fulfillment_orders']);
        $this->assertTrue($order['unidentified_lines']);
        $this->assertFalse($order['cancelled']);
        $this->assertSame('Apt 4', $order['address']['street_2']);
        $this->assertNull($order['address']['phone']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['variables']['search'], 'name:1001'));
    }

    public function test_client_marks_partially_read_orders_incomplete(): void
    {
        $this->fakeShopify([
            $this->shopifyOrder('1', [$this->fulfillmentOrder('OPEN', [$this->line('tee', 1)], true)]),
            $this->shopifyOrder('2', [$this->fulfillmentOrder('OPEN', [$this->line('tee', 1)])], true),
            $this->shopifyOrder('3', [], false, '2026-10-01T00:00:00Z'),
        ]);
        $store = new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']);

        $result = $this->client()->preShipmentRemainingItems($store, ['1', '2', '3']);

        $this->assertFalse($result['1'][0]['complete']);
        $this->assertFalse($result['2'][0]['complete']);
        $this->assertFalse($result['3'][0]['has_fulfillment_orders']);
        $this->assertTrue($result['3'][0]['cancelled']);
    }

    public function test_client_fails_instead_of_guessing_when_the_order_page_is_not_the_last(): void
    {
        $this->fakeShopify([], true);

        $this->expectException(ShopifyGraphqlException::class);
        $this->client()->preShipmentRemainingItems(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), ['1']);
    }

    public function test_client_rejects_invalid_order_numbers_without_calling_shopify(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);
        $this->client()->preShipmentRemainingItems(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), ['1001 OR name:*']);
    }

    private function shipStationOrder(string $number, int $orderId, array $items, array $extra = []): array
    {
        return $extra + ['orderId' => $orderId, 'orderNumber' => $number, 'orderStatus' => 'awaiting_shipment', 'advancedOptions' => ['storeId' => 42], 'shipTo' => ['name' => 'Jane Doe', 'street1' => '1 Main St', 'street2' => 'Apt 4', 'city' => 'Austin', 'state' => 'TX', 'postalCode' => '78701', 'country' => 'US'], 'items' => $items];
    }

    public function test_page_is_gated_store_scoped_and_reports_each_order_state(): void
    {
        $this->get(route('print-queue.drift-audit'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('print-queue.drift-audit'))->assertForbidden();

        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchBacklogOrders')->once()->with(42)->andReturn([
            $this->shipStationOrder('1001', 1, [['sku' => 'TEE', 'quantity' => 2], ['sku' => 'MUG', 'quantity' => 1], ['sku' => null, 'name' => 'Discount', 'quantity' => 1, 'adjustment' => true]], ['internationalOptions' => ['customsItems' => [['description' => 'Tee']]]]),
            $this->shipStationOrder('1002', 2, [['sku' => 'TEE', 'quantity' => 1]]),
            $this->shipStationOrder('1003', 3, [['sku' => 'TEE', 'quantity' => 1]]),
        ]);
        $address = ['name' => 'Jane Doe', 'company' => null, 'street_1' => '1 Main St', 'street_2' => 'Apt 4', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US', 'phone' => null];
        $shopify = fn (array $remaining, array $overrides = []): array => [$overrides + ['id' => 'gid://shopify/Order/1', 'cancelled' => false, 'complete' => true, 'has_fulfillment_orders' => true, 'remaining' => $remaining, 'unidentified_lines' => false, 'address' => $address]];
        $orders = Mockery::mock(ShopifyOrders::class);
        $orders->shouldReceive('preShipmentRemainingItems')->once()->withArgs(fn (Store $store, array $numbers): bool => $numbers === ['1001', '1002', '1003'])->andReturn([
            '1001' => $shopify(['tee' => 1]),
            '1002' => $shopify(['tee' => 1]),
            '1003' => [],
        ]);
        $this->app->instance(ShopifyOrders::class, $orders);

        $this->actingAs($operator)->get(route('print-queue.drift-audit'))->assertOk()->assertDontSeeText('1001');
        $response = $this->get(route('print-queue.drift-audit', ['generate' => 1]))->assertOk();

        $response->assertSeeText('1001')->assertSeeText('mug × 1')->assertSeeText('tee × 1')->assertSeeText(__('Items changed: re-check the customs declaration.'))->assertDontSeeText('1002')->assertSeeText(__('The order was not found in Shopify.'));
        $rows = collect($response->viewData('result')['rows'])->keyBy('number');
        $this->assertSame(['different', 'unknown'], [$rows['1001']['state'], $rows['1003']['state']]);
        $this->assertSame(['mug' => 1, 'tee' => 1], $rows['1001']['extra']);
        $this->assertEquals(['match' => 1, 'different' => 1, 'unknown' => 1], $response->viewData('result')['counts']);
    }

    public function test_page_caps_the_checked_orders_and_says_when_more_exist(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $open = [];
        foreach (range(1, 52) as $number) {
            $open[] = $this->shipStationOrder((string) (1000 + $number), $number, [['sku' => 'TEE', 'quantity' => 1]]);
        }
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchBacklogOrders')->once()->andReturn($open);
        $orders = Mockery::mock(ShopifyOrders::class);
        $orders->shouldReceive('preShipmentRemainingItems')->once()->withArgs(fn (Store $store, array $numbers): bool => count($numbers) === 50)->andReturn([]);
        $this->app->instance(ShopifyOrders::class, $orders);

        $response = $this->actingAs($operator)->get(route('print-queue.drift-audit', ['generate' => 1, 'state' => 'all']))->assertOk();

        $response->assertSeeText(__('Showing the oldest :shown of :total open orders; the rest were not checked.', ['shown' => 50, 'total' => 52]));
        $this->assertSame(50, $response->viewData('result')['checked']);
    }

    public function test_failures_are_reported_without_leaking_details(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchBacklogOrders')->andThrow(new RuntimeException('secret-token boom'));

        $this->actingAs($operator)->get(route('print-queue.drift-audit', ['generate' => 1]))->assertOk()->assertSeeText(__('The drift check could not be completed. Try again.'))->assertDontSeeText('secret-token');
    }

    public function test_unconfigured_shipstation_is_reported(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        ShipStationFixtures::bindUnconfigured();

        $this->actingAs($operator)->get(route('print-queue.drift-audit', ['generate' => 1]))->assertOk()->assertSeeText(__('ShipStation is not configured completely for this store.'));
    }
}
