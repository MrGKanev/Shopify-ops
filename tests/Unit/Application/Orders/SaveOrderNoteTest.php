<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\SaveOrderNote;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use Mockery;
use PHPUnit\Framework\TestCase;

class SaveOrderNoteTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_delegates_to_the_shopify_gateway(): void
    {
        $store = new Store;
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('updateOrderNote')->once()->with($store, '123', 'Fragile — handle with care')->andReturnNull();

        $result = (new SaveOrderNote($shopify))->handle($store, '123', 'Fragile — handle with care');

        $this->assertNull($result);
    }
}
