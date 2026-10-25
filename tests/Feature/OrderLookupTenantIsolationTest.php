<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderLookupTenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('lookupRoutes')]
    public function test_a_shared_shipstation_account_does_not_disclose_other_store_orders(string $method, string $route, array $input): void
    {
        [$viewer] = $this->userWithStore(false, ['store_number' => '42']);
        $shopify = $this->mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->zeroOrMoreTimes()->andReturn([]);
        $client = $this->mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->with('1001', 42)->once()->andReturn([['orderId' => 99, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 99], 'orderStatus' => 'FOREIGN-STATUS', 'customerEmail' => 'private@example.com']]);
        $client->shouldReceive('findByOrderNumber')->with('1002', 42)->zeroOrMoreTimes()->andReturn([]);
        $client->shouldNotReceive('getOrderShipments');
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($client);
        $this->actingAs($viewer);

        $response = $this->call($method, route($route), $input);

        $response->assertOk()->assertDontSeeText('FOREIGN-STATUS')->assertDontSeeText('private@example.com');
    }

    public static function lookupRoutes(): array
    {
        return [
            'single order' => ['GET', 'orders.lookup', ['order_number' => '1001']],
            'packing slip' => ['POST', 'orders.packing-slip.store', ['order_number' => '1001']],
            'batch lookup' => ['POST', 'orders.spot-check.store', ['orders' => '1001', 'mode' => 'shipstation']],
            'order comparison' => ['GET', 'orders.compare', ['order_a' => '1001', 'order_b' => '1002']],
        ];
    }

    public function test_single_order_lookup_excludes_unmatched_shipments_even_when_the_number_is_shared(): void
    {
        [$viewer] = $this->userWithStore(false, ['store_number' => '42']);
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->once()->andReturn([]);
        $client = $this->mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->once()->with('1001', 42)->andReturn([['orderId' => 1, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 42], 'orderStatus' => 'shipped']]);
        $client->shouldReceive('getOrderShipments')->once()->with('1001', true)->andReturn([
            ['shipmentId' => 1, 'orderId' => 1, 'trackingNumber' => 'OWN-TRACKING', 'carrierCode' => 'ups'],
            ['shipmentId' => 1, 'orderId' => 1, 'trackingNumber' => 'OWN-TRACKING', 'carrierCode' => 'ups'],
            ['shipmentId' => 2, 'orderId' => 99, 'trackingNumber' => 'FOREIGN-TRACKING', 'carrierCode' => 'ups'],
        ]);
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($client);

        $response = $this->actingAs($viewer)->get(route('orders.lookup', ['order_number' => '1001']));

        $response->assertSeeText('OWN-TRACKING')->assertDontSeeText('FOREIGN-TRACKING');
        $response->assertViewHas('result', fn ($result): bool => count($result->shipStationShipments) === 1);
    }

    #[DataProvider('lookupRoutes')]
    public function test_missing_store_identity_never_falls_back_to_an_account_wide_lookup(string $method, string $route, array $input): void
    {
        [$viewer] = $this->userWithStore(false, ['store_number' => null]);
        $shopify = $this->mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->zeroOrMoreTimes()->andReturn([]);
        $client = $this->mock(ShipStationClientContract::class);
        $client->shouldNotReceive('findByOrderNumber');
        $client->shouldNotReceive('getOrderShipments');
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($client);
        $this->actingAs($viewer);

        $response = $this->call($method, route($route), $input);

        $response->assertOk()->assertViewHas('result', null);
    }

    public function test_timeline_excludes_other_store_orders_even_when_the_shopify_order_is_known(): void
    {
        [$viewer] = $this->userWithStore(false, ['store_number' => '42']);
        $this->fakeTimelineOrder();
        $client = $this->mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->once()->with('1001', 42)->andReturn([['orderId' => 99, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 99], 'orderStatus' => 'FOREIGN-STATUS']]);
        $client->shouldNotReceive('getOrderShipments');
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($client);

        $response = $this->actingAs($viewer)->get(route('orders.timeline', ['order_number' => '1001']));

        $response->assertDontSeeText('FOREIGN-STATUS')->assertViewHas('result', fn ($result): bool => $result !== null && $result->shipStationOrders === [] && $result->shipStationShipments === []);
    }

    public function test_timeline_includes_only_shipments_from_the_confirmed_store_order(): void
    {
        [$viewer] = $this->userWithStore(false, ['store_number' => '42']);
        $this->fakeTimelineOrder();
        $client = $this->mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->once()->with('1001', 42)->andReturn([['orderId' => 1, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 42], 'orderStatus' => 'shipped']]);
        $client->shouldReceive('getOrderShipments')->once()->with('1001', true)->andReturn([
            ['shipmentId' => 1, 'orderId' => 1, 'trackingNumber' => 'OWN-TRACKING', 'shipDate' => '2026-10-10T10:00:00Z'],
            ['shipmentId' => 2, 'orderId' => 99, 'trackingNumber' => 'FOREIGN-TRACKING', 'shipDate' => '2026-10-10T10:00:00Z'],
        ]);
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($client);

        $response = $this->actingAs($viewer)->get(route('orders.timeline', ['order_number' => '1001']));

        $response->assertDontSeeText('FOREIGN-TRACKING')->assertViewHas('result', fn ($result): bool => count($result->shipStationShipments) === 1 && $result->shipStationShipments[0]['trackingNumber'] === 'OWN-TRACKING');
    }

    #[DataProvider('unconfirmedShipments')]
    public function test_unconfirmed_shipment_identity_is_not_assumed_to_belong_to_the_single_order(array $shipments): void
    {
        [$viewer] = $this->userWithStore(false, ['store_number' => '42']);
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->once()->andReturn([]);
        $client = $this->mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->once()->with('1001', 42)->andReturn([['orderId' => 1, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 42], 'orderStatus' => 'shipped']]);
        $client->shouldReceive('getOrderShipments')->once()->with('1001', true)->andReturn($shipments);
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($client);

        $response = $this->actingAs($viewer)->get(route('orders.lookup', ['order_number' => '1001']));

        $response->assertViewHas('result', null)->assertViewHas('lookupFailed', true)->assertDontSeeText('PRIVATE-TRACKING');
    }

    public static function unconfirmedShipments(): array
    {
        return [
            'missing parent identity' => [[['shipmentId' => 1, 'trackingNumber' => 'PRIVATE-TRACKING']]],
            'missing label identity' => [[['orderId' => 1, 'trackingNumber' => 'PRIVATE-TRACKING']]],
            'conflicting store' => [[['shipmentId' => 1, 'orderId' => 1, 'trackingNumber' => 'PRIVATE-TRACKING', 'advancedOptions' => ['storeId' => 99]]]],
            'changed duplicate' => [[['shipmentId' => 1, 'orderId' => 1, 'trackingNumber' => 'PRIVATE-TRACKING'], ['shipmentId' => 1, 'orderId' => 1, 'trackingNumber' => 'OTHER-TRACKING']]],
        ];
    }

    private function fakeTimelineOrder(): void
    {
        $shopify = $this->mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([['id' => 1, 'admin_graphql_api_id' => 'gid://shopify/Order/1', 'name' => '#1001', 'created_at' => '2026-10-10T00:00:00Z', 'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled', 'fulfillments' => [], 'line_items' => []]]);
        $shopify->shouldReceive('getOrderEvents')->once()->andReturn([]);
    }
}
