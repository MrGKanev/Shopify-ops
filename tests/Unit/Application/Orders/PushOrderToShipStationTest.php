<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\PushOrderToShipStation;
use App\Application\Orders\RecordPush;
use App\Application\Orders\ShippingAddressNeedsReview;
use App\Domain\Orders\ShipStationRelevantFingerprint;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class PushOrderToShipStationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preview_builds_the_payload_without_creating_an_order(): void
    {
        $store = Store::factory()->create();
        $order = $this->order();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->with($store, '1001')->andReturn([$order]);
        $client = ShipStationFixtures::bindClient(1, $store);
        $client->shouldReceive('buildOrderPayload')->once()->with($order)->andReturn(['orderNumber' => '1001']);
        $client->shouldNotReceive('createOrder');
        $recordPush = app(RecordPush::class);

        $preview = (new PushOrderToShipStation($shopify, app(ShipStationClientFactory::class), $recordPush))->preview($store, '1001');

        $this->assertSame(['payload' => ['orderNumber' => '1001'], 'address_issues' => []], $preview);
        $this->assertDatabaseCount('push_logs', 0);
    }

    public function test_preview_throws_when_the_shopify_order_is_not_found(): void
    {
        $store = Store::factory()->create();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $recordPush = app(RecordPush::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Order 999 not found in Shopify.');

        (new PushOrderToShipStation($shopify, app(ShipStationClientFactory::class), $recordPush))->preview($store, '999');
    }

    public function test_preview_throws_when_shipstation_is_not_configured(): void
    {
        $store = Store::factory()->create();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([$this->order()]);
        ShipStationFixtures::bindUnconfigured(1);
        $recordPush = app(RecordPush::class);

        $this->expectException(LogicException::class);

        (new PushOrderToShipStation($shopify, app(ShipStationClientFactory::class), $recordPush))->preview($store, '1001');
    }

    public function test_handle_creates_the_order_and_records_the_push(): void
    {
        $store = Store::factory()->create();
        $order = $this->order();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->with($store, '1001')->andReturn([$order]);
        $client = ShipStationFixtures::bindClient(1, $store);
        $client->shouldReceive('buildOrderPayload')->andReturn([]);
        $client->shouldReceive('createOrder')->once()->with($order)->andReturn(['orderId' => 555, 'orderNumber' => '1001']);
        $recordPush = app(RecordPush::class);

        $result = (new PushOrderToShipStation($shopify, app(ShipStationClientFactory::class), $recordPush))->handle($store, '1001');

        $this->assertSame(['order_number' => '1001', 'shopify_order_number' => '1001', 'ss_order_id' => 555], $result);
        $log = $store->pushLogs()->sole();
        $this->assertSame(['1001', '1', '555', 'success'], [$log->order_number, $log->shopify_id, (string) $log->shipstation_order_id, $log->status]);
        $this->assertTrue(now()->equalTo($log->pushed_at));
        $this->assertSame(ShipStationRelevantFingerprint::hash([]), $log->payload_hash);
    }

    public function test_handle_falls_back_to_the_requested_order_number_when_shipstation_omits_it(): void
    {
        $store = Store::factory()->create();
        $order = $this->order();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([$order]);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('buildOrderPayload')->andReturn([]);
        $client->shouldReceive('createOrder')->once()->andReturn(['orderId' => 555]);
        $recordPush = app(RecordPush::class);

        $result = (new PushOrderToShipStation($shopify, app(ShipStationClientFactory::class), $recordPush))->handle($store, '1001');

        $this->assertSame('1001', $result['order_number']);
        $this->assertSame('555', (string) $store->pushLogs()->sole()->shipstation_order_id);
    }

    public function test_handle_records_a_failed_push_before_rethrowing_the_error(): void
    {
        $store = Store::factory()->create();
        $order = $this->order();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->with($store, '1001')->andReturn([$order]);
        $client = ShipStationFixtures::bindClient(1, $store);
        $client->shouldReceive('buildOrderPayload')->andReturn([]);
        $client->shouldReceive('createOrder')->once()->with($order)->andThrow(new RuntimeException('API secret must not be persisted'));
        $recordPush = app(RecordPush::class);

        try {
            (new PushOrderToShipStation($shopify, app(ShipStationClientFactory::class), $recordPush))->handle($store, '1001');
            $this->fail('The ShipStation error must be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('API secret must not be persisted', $exception->getMessage());
        }

        $log = $store->pushLogs()->sole();
        $this->assertSame(['1001', '1', 'failed', null, RuntimeException::class], [$log->order_number, $log->shopify_id, $log->status, $log->shipstation_order_id, $log->error_category]);
        $this->assertStringNotContainsString('secret', json_encode($log->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_preview_lists_shipping_address_problems(): void
    {
        $store = Store::factory()->create();
        $order = $this->order(['city' => '', 'phone' => '555']);
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([$order]);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('buildOrderPayload')->once()->andReturn([]);

        $preview = (new PushOrderToShipStation($shopify, app(ShipStationClientFactory::class), app(RecordPush::class)))->preview($store, '1001');

        $this->assertSame(['no_city', 'invalid_phone'], array_column($preview['address_issues'], 'code'));
    }

    public function test_handle_stops_on_critical_address_problems_until_confirmed(): void
    {
        $store = Store::factory()->create();
        $order = $this->order(['address1' => '']);
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->twice()->andReturn([$order]);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('buildOrderPayload')->andReturn([]);
        $client->shouldReceive('createOrder')->once()->with($order)->andReturn(['orderId' => 555, 'orderNumber' => '1001']);
        $recordPush = app(RecordPush::class);
        $push = new PushOrderToShipStation($shopify, app(ShipStationClientFactory::class), $recordPush);

        try {
            $push->handle($store, '1001');
            $this->fail('The push should stop on a missing street address.');
        } catch (ShippingAddressNeedsReview $exception) {
            $this->assertSame(['no_address1'], array_column($exception->issues, 'code'));
        }

        $this->assertDatabaseCount('push_logs', 0);
        $this->assertSame(555, $push->handle($store, '1001', confirmAddressIssues: true)['ss_order_id']);
        $this->assertSame('success', $store->pushLogs()->sole()->status);
    }

    /**
     * @param  array<string, string>  $address
     * @return array<string, mixed>
     */
    private function order(array $address = []): array
    {
        return ['id' => 1, 'name' => '#1001', 'shipping_address' => [...['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '123 Main Street', 'city' => 'Boston', 'province_code' => 'MA', 'zip' => '02101', 'country_code' => 'US', 'phone' => '617-555-0100'], ...$address]];
    }
}
