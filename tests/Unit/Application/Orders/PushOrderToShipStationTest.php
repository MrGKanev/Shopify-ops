<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\PushOrderToShipStation;
use App\Application\Orders\RecordPush;
use App\Application\Orders\ShippingAddressNeedsReview;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use LogicException;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PushOrderToShipStationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_preview_builds_the_payload_without_creating_an_order(): void
    {
        $store = new Store;
        $order = $this->order();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->with($store, '1001')->andReturn([$order]);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('buildOrderPayload')->once()->with($order)->andReturn(['orderNumber' => '1001']);
        $client->shouldNotReceive('createOrder');
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->with($store)->andReturn($client);
        $recordPush = Mockery::mock(RecordPush::class);
        $recordPush->shouldNotReceive('handle');

        $preview = (new PushOrderToShipStation($shopify, $factory, $recordPush))->preview($store, '1001');

        $this->assertSame(['payload' => ['orderNumber' => '1001'], 'address_issues' => []], $preview);
    }

    public function test_preview_throws_when_the_shopify_order_is_not_found(): void
    {
        $store = new Store;
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $recordPush = Mockery::mock(RecordPush::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Order 999 not found in Shopify.');

        (new PushOrderToShipStation($shopify, $factory, $recordPush))->preview($store, '999');
    }

    public function test_preview_throws_when_shipstation_is_not_configured(): void
    {
        $store = new Store;
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([$this->order()]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn(null);
        $recordPush = Mockery::mock(RecordPush::class);

        $this->expectException(LogicException::class);

        (new PushOrderToShipStation($shopify, $factory, $recordPush))->preview($store, '1001');
    }

    public function test_handle_creates_the_order_and_records_the_push(): void
    {
        $store = new Store;
        $order = $this->order();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->with($store, '1001')->andReturn([$order]);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('createOrder')->once()->with($order)->andReturn(['orderId' => 555, 'orderNumber' => '1001']);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->with($store)->andReturn($client);
        $recordPush = Mockery::mock(RecordPush::class);
        $recordPush->shouldReceive('handle')->once()->with($store, '1001', '1', 555);

        $result = (new PushOrderToShipStation($shopify, $factory, $recordPush))->handle($store, '1001');

        $this->assertSame(['order_number' => '1001', 'shopify_order_number' => '1001', 'ss_order_id' => 555], $result);
    }

    public function test_handle_falls_back_to_the_requested_order_number_when_shipstation_omits_it(): void
    {
        $store = new Store;
        $order = $this->order();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([$order]);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('createOrder')->once()->andReturn(['orderId' => 555]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn($client);
        $recordPush = Mockery::mock(RecordPush::class);
        $recordPush->shouldReceive('handle')->once()->with($store, '1001', '1', 555);

        $result = (new PushOrderToShipStation($shopify, $factory, $recordPush))->handle($store, '1001');

        $this->assertSame('1001', $result['order_number']);
    }

    public function test_handle_records_a_failed_push_before_rethrowing_the_error(): void
    {
        $store = new Store;
        $order = $this->order();
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->with($store, '1001')->andReturn([$order]);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('createOrder')->once()->with($order)->andThrow(new RuntimeException('API secret must not be persisted'));
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->with($store)->andReturn($client);
        $recordPush = Mockery::mock(RecordPush::class);
        $recordPush->shouldReceive('failed')->once()->with($store, '1001', '1', Mockery::type(RuntimeException::class));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API secret must not be persisted');

        (new PushOrderToShipStation($shopify, $factory, $recordPush))->handle($store, '1001');
    }

    public function test_preview_lists_shipping_address_problems(): void
    {
        $store = new Store;
        $order = $this->order(['city' => '', 'phone' => '555']);
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([$order]);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('buildOrderPayload')->once()->andReturn([]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn($client);

        $preview = (new PushOrderToShipStation($shopify, $factory, Mockery::mock(RecordPush::class)))->preview($store, '1001');

        $this->assertSame(['no_city', 'invalid_phone'], array_column($preview['address_issues'], 'code'));
    }

    public function test_handle_stops_on_critical_address_problems_until_confirmed(): void
    {
        $store = new Store;
        $order = $this->order(['address1' => '']);
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->twice()->andReturn([$order]);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('createOrder')->once()->with($order)->andReturn(['orderId' => 555, 'orderNumber' => '1001']);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn($client);
        $recordPush = Mockery::mock(RecordPush::class);
        $recordPush->shouldNotReceive('failed');
        $recordPush->shouldReceive('handle')->once()->with($store, '1001', '1', 555);
        $push = new PushOrderToShipStation($shopify, $factory, $recordPush);

        try {
            $push->handle($store, '1001');
            $this->fail('The push should stop on a missing street address.');
        } catch (ShippingAddressNeedsReview $exception) {
            $this->assertSame(['no_address1'], array_column($exception->issues, 'code'));
        }

        $this->assertSame(555, $push->handle($store, '1001', confirmAddressIssues: true)['ss_order_id']);
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
