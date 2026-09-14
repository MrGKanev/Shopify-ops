<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\PushOrderToShipStation;
use App\Application\Orders\RecordPush;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
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
        $order = ['id' => 1, 'name' => '#1001'];
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->with($store, '1001')->andReturn([$order]);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('buildOrderPayload')->once()->with($order)->andReturn(['orderNumber' => '1001']);
        $client->shouldNotReceive('createOrder');
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->with($store)->andReturn($client);
        $recordPush = Mockery::mock(RecordPush::class);
        $recordPush->shouldNotReceive('handle');

        $payload = (new PushOrderToShipStation($shopify, $factory, $recordPush))->preview($store, '1001');

        $this->assertSame(['orderNumber' => '1001'], $payload);
    }

    public function test_preview_throws_when_the_shopify_order_is_not_found(): void
    {
        $store = new Store;
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
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
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([['id' => 1, 'name' => '#1001']]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn(null);
        $recordPush = Mockery::mock(RecordPush::class);

        $this->expectException(LogicException::class);

        (new PushOrderToShipStation($shopify, $factory, $recordPush))->preview($store, '1001');
    }

    public function test_handle_creates_the_order_and_records_the_push(): void
    {
        $store = new Store;
        $order = ['id' => 1, 'name' => '#1001'];
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
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
        $order = ['id' => 1, 'name' => '#1001'];
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
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
}
