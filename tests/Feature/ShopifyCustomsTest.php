<?php

namespace Tests\Feature;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClient;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Integrations\Shopify\ShopifyCustoms;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use UnexpectedValueException;

class ShopifyCustomsTest extends TestCase
{
    public function test_product_variants_follow_nested_pages_and_catalog_cursor_is_preserved(): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsCatalogProducts/'), ['after' => 'product-before'])->once()->andReturn(['data' => ['products' => $this->connection([['id' => 'gid://shopify/Product/1', 'title' => 'Shirt']], 'product-next')]]);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsProductVariants/'), ['id' => 'gid://shopify/Product/1', 'after' => null])->once()->andReturn($this->productResponse([['id' => 'v1']], 'v-next'));
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsProductVariants/'), ['id' => 'gid://shopify/Product/1', 'after' => 'v-next'])->once()->andReturn($this->productResponse([['id' => 'v2']]));

        $data = app(ShopifyCustoms::class)->catalog(Store::factory()->make(), 'product-before');

        $this->assertSame(['v1', 'v2'], array_column($data['products'][0]['customs_variants'], 'id'));
        $this->assertSame('product-next', $data['next_after']);
        $this->assertTrue($data['truncated']);
        $this->assertFalse($data['products'][0]['customs_truncated']);
    }

    #[TestWith(['changed'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['cursor'])]
    #[TestWith(['missing'])]
    public function test_changed_or_unconfirmed_nested_data_is_rejected(string $case): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsCatalogProducts/'), Mockery::any())->andReturn(['data' => ['products' => $this->connection([['id' => 'gid://shopify/Product/1']])]]);
        $first = $this->productResponse([['id' => 'v1']], 'next');
        $second = $this->productResponse([['id' => $case === 'duplicate' ? 'v1' : 'v2']]);
        if ($case === 'changed') {
            $second['data']['product']['updatedAt'] = '2026-10-07T00:01:00Z';
        } elseif ($case === 'cursor') {
            $second['data']['product']['variants']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'next'];
        } elseif ($case === 'missing') {
            unset($second['data']['product']['variants']['pageInfo']);
        }
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsProductVariants/'), ['id' => 'gid://shopify/Product/1', 'after' => null])->andReturn($first);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsProductVariants/'), ['id' => 'gid://shopify/Product/1', 'after' => 'next'])->andReturn($second);

        $this->expectException(UnexpectedValueException::class);
        app(ShopifyCustoms::class)->catalog(Store::factory()->make());
    }

    public function test_variant_page_limit_remains_explicitly_incomplete(): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsCatalogProducts/'), Mockery::any())->andReturn(['data' => ['products' => $this->connection([['id' => 'gid://shopify/Product/1']])]]);
        $page = 0;
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsProductVariants/'), Mockery::any())->times(20)->andReturnUsing(function () use (&$page): array {
            $page++;

            return $this->productResponse([['id' => 'v'.$page]], 'cursor'.$page);
        });

        $data = app(ShopifyCustoms::class)->catalog(Store::factory()->make());

        $this->assertTrue($data['truncated']);
        $this->assertTrue($data['products'][0]['customs_truncated']);
        $this->assertCount(20, $data['products'][0]['customs_variants']);
    }

    public function test_order_items_are_paginated_with_an_exact_resource_identity(): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsOrderLines/'), ['id' => 'gid://shopify/Order/1', 'after' => null])->once()->andReturn(['data' => ['order' => ['id' => 'gid://shopify/Order/1', 'updatedAt' => '2026-10-07T00:00:00Z', 'lineItems' => $this->connection([['id' => 'l1']], 'next')]]]);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsOrderLines/'), ['id' => 'gid://shopify/Order/1', 'after' => 'next'])->once()->andReturn(['data' => ['order' => ['id' => 'gid://shopify/Order/1', 'updatedAt' => '2026-10-07T00:00:00Z', 'lineItems' => $this->connection([['id' => 'l2']])]]]);

        $data = app(ShopifyCustoms::class)->order(Store::factory()->make(), 'gid://shopify/Order/1');

        $this->assertSame(['l1', 'l2'], array_column($data['lines'], 'id'));
        $this->assertFalse($data['truncated']);
    }

    public function test_shipstation_products_paginate_and_deduplicate_identity(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/products*' => fn (Request $request) => Http::response(['products' => [['productId' => 1, 'sku' => 'A'], ['productId' => (int) $request['page'] + 1, 'sku' => 'B'.$request['page']]], 'pages' => 2])]);

        $result = (new ShipStationClient('key', 'secret'))->customsProducts();

        $this->assertSame([1, 2, 3], array_column($result['products'], 'productId'));
        $this->assertFalse($result['truncated']);
        Http::assertSent(fn (Request $request): bool => $request['showInactive'] === 'false' && (int) $request['page'] === 2);
    }

    public function test_shipstation_missing_pagination_is_not_a_complete_empty_catalog(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/products*' => Http::response(['products' => []])]);

        $this->expectException(UnexpectedResponse::class);
        (new ShipStationClient('key', 'secret'))->customsProducts();
    }

    /** @param list<array<string, mixed>> $nodes
     * @return array<string, mixed> */
    private function connection(array $nodes, ?string $next = null): array
    {
        return ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $next !== null, 'endCursor' => $next]];
    }

    /** @param list<array<string, mixed>> $variants
     * @return array<string, mixed> */
    private function productResponse(array $variants, ?string $next = null): array
    {
        return ['data' => ['product' => ['id' => 'gid://shopify/Product/1', 'updatedAt' => '2026-10-07T00:00:00Z', 'variants' => $this->connection($variants, $next)]]];
    }
}
