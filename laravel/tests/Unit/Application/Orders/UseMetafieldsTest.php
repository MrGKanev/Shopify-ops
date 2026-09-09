<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\UseMetafields;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use Mockery;
use PHPUnit\Framework\TestCase;

class UseMetafieldsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_batch_lookup_preserves_missing_orders_and_filters_values(): void
    {
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('findByOrderNumbers')->andReturn(['1001' => [['id' => 42, 'name' => '#1001']], '1002' => []]);
        $gateway->shouldReceive('orderMetafields')->with(Mockery::type(Store::class), [42])->andReturn(['42' => [['namespace' => 'custom', 'key' => 'gift', 'value' => 'Happy birthday'], ['namespace' => 'other', 'key' => 'note', 'value' => 'No match']]]);

        $rows = (new UseMetafields($gateway))->lookup(new Store, ['#1001', '1002'], 'birthday');

        $this->assertCount(1, $rows[0]['metafields']);
        $this->assertFalse($rows[1]['found']);
    }
}
