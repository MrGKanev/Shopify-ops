<?php

namespace Tests\Feature;

use App\Application\Reports\CaptureShopifyBacklog;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class ShopifyBacklogIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_paginates_fulfillment_orders_and_nested_lines_without_an_order_date_cutoff(): void
    {
        $store = Store::factory()->create();
        $calls = [];
        Http::preventStrayRequests();
        Http::fake(['*.myshopify.com/admin/api/2026-07/graphql.json' => function (Request $request) use (&$calls) {
            $query = $request['query'];
            $calls[] = $query;
            if (str_contains($query, 'query ShopifyBacklogAccess')) {
                return Http::response(['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_merchant_managed_fulfillment_orders'], ['handle' => 'read_orders']]]]]);
            }
            if (str_contains($query, 'query ShopifyBacklogLineItems')) {
                $this->assertSame('gid://shopify/FulfillmentOrder/1', $request['variables']['id']);
                $this->assertSame('line-page-1', $request['variables']['after']);

                return Http::response(['data' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/1', 'lineItems' => ['nodes' => [$this->item(2)], 'pageInfo' => ['hasNextPage' => false]]]]]);
            }
            $this->assertStringNotContainsString('created_at', $query);
            if ($request['variables']['after'] === null) {
                $node = $this->fulfillment();
                $node['lineItems']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'line-page-1'];

                return Http::response($this->page([$node], true, 'orders-page-1'));
            }
            $this->assertSame('orders-page-1', $request['variables']['after']);

            return Http::response($this->page([]));
        }]);

        $snapshot = app(CaptureShopifyBacklog::class)->handle($store);

        $this->assertSame(4, $snapshot->result['remaining_units']);
        $this->assertSame('0.4', $snapshot->result['currencies'][0]['estimated_value']);
        $this->assertSame(['read_merchant_managed_fulfillment_orders', 'read_orders'], $snapshot->result['scopes']);
        $this->assertCount(4, $calls);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_quantities_and_partial_connections_never_create_a_daily_observation(array $patch, string $exception): void
    {
        $store = Store::factory()->create();
        $node = array_replace_recursive($this->fulfillment(), $patch);
        $this->fake([$node]);
        try {
            $this->expectException($exception);
            app(CaptureShopifyBacklog::class)->handle($store);
        } finally {
            $this->assertDatabaseCount('audit_snapshots', 0);
        }
    }

    public static function invalidPayloads(): array
    {
        return [
            'negative remainder' => [['lineItems' => ['nodes' => [['remainingQuantity' => -1]]]], UnexpectedValueException::class],
            'unknown quantity' => [['lineItems' => ['nodes' => [['remainingQuantity' => null]]]], UnexpectedValueException::class],
            'more than total' => [['lineItems' => ['nodes' => [['remainingQuantity' => 11]]]], UnexpectedValueException::class],
            'unknown status' => [['status' => 'NEW_STATUS'], UnexpectedValueException::class],
            'missing shipping flag' => [['lineItems' => ['nodes' => [['requiresShipping' => null]]]], UnexpectedValueException::class],
            'incomplete connection' => [['lineItems' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => '']]], ShopifyGraphqlException::class],
            'invalid cancellation flag' => [['order' => ['cancelledAt' => false]], UnexpectedValueException::class],
            'invalid currency' => [['order' => ['presentmentCurrencyCode' => 'usd']], UnexpectedValueException::class],
        ];
    }

    public function test_duplicate_fulfillment_orders_are_rejected_instead_of_double_counted(): void
    {
        $store = Store::factory()->create();
        $this->fake([$this->fulfillment(), $this->fulfillment()]);
        try {
            $this->expectException(UnexpectedValueException::class);
            app(CaptureShopifyBacklog::class)->handle($store);
        } finally {
            $this->assertDatabaseCount('audit_snapshots', 0);
        }
    }

    public function test_absent_access_is_not_an_empty_backlog(): void
    {
        $store = Store::factory()->create();
        Http::preventStrayRequests();
        Http::fake(['*.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_orders']]]]])]);
        try {
            $this->expectException(ShopifyGraphqlException::class);
            app(CaptureShopifyBacklog::class)->handle($store);
        } finally {
            $this->assertDatabaseCount('audit_snapshots', 0);
            Http::assertSentCount(1);
        }
    }

    public function test_changed_nested_fulfillment_identity_is_rejected(): void
    {
        $store = Store::factory()->create();
        $node = $this->fulfillment();
        $node['lineItems']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'line-page'];
        Http::preventStrayRequests();
        Http::fake(['*.myshopify.com/admin/api/2026-07/graphql.json' => function (Request $request) use ($node) {
            if (str_contains($request['query'], 'query ShopifyBacklogAccess')) {
                return Http::response(['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_merchant_managed_fulfillment_orders']]]]]);
            }
            if (str_contains($request['query'], 'query ShopifyBacklogLineItems')) {
                return Http::response(['data' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/999']]]);
            }

            return Http::response($this->page([$node]));
        }]);
        try {
            $this->expectException(ShopifyGraphqlException::class);
            app(CaptureShopifyBacklog::class)->handle($store);
        } finally {
            $this->assertDatabaseCount('audit_snapshots', 0);
        }
    }

    public function test_a_repeated_nested_cursor_fails_without_writing_partial_counts(): void
    {
        $store = Store::factory()->create();
        $node = $this->fulfillment();
        $node['lineItems']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'same-cursor'];
        Http::preventStrayRequests();
        Http::fake(['*.myshopify.com/admin/api/2026-07/graphql.json' => function (Request $request) use ($node) {
            if (str_contains($request['query'], 'query ShopifyBacklogAccess')) {
                return Http::response(['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_merchant_managed_fulfillment_orders']]]]]);
            }
            if (str_contains($request['query'], 'query ShopifyBacklogLineItems')) {
                return Http::response(['data' => ['fulfillmentOrder' => $node]]);
            }

            return Http::response($this->page([$node]));
        }]);
        try {
            $this->expectException(ShopifyGraphqlException::class);
            app(CaptureShopifyBacklog::class)->handle($store);
        } finally {
            $this->assertDatabaseCount('audit_snapshots', 0);
            Http::assertSentCount(3);
        }
    }

    public function test_duplicate_line_item_ids_across_distinct_fulfillment_orders_are_not_counted_twice(): void
    {
        $store = Store::factory()->create();
        $second = $this->fulfillment();
        $second['id'] = 'gid://shopify/FulfillmentOrder/2';
        $this->fake([$this->fulfillment(), $second]);
        try {
            $this->expectException(UnexpectedValueException::class);
            app(CaptureShopifyBacklog::class)->handle($store);
        } finally {
            $this->assertDatabaseCount('audit_snapshots', 0);
        }
    }

    private function fake(array $nodes): void
    {
        Http::preventStrayRequests();
        Http::fake(['*.myshopify.com/admin/api/2026-07/graphql.json' => fn (Request $request) => Http::response(str_contains($request['query'], 'query ShopifyBacklogAccess') ? ['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_merchant_managed_fulfillment_orders']]]]] : $this->page($nodes))]);
    }

    private function page(array $nodes, bool $next = false, ?string $cursor = null): array
    {
        return ['data' => ['fulfillmentOrders' => ['edges' => array_map(fn (array $node): array => ['node' => $node], $nodes), 'pageInfo' => ['hasNextPage' => $next, 'endCursor' => $cursor]]]];
    }

    private function fulfillment(): array
    {
        return ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN', 'order' => ['id' => 'gid://shopify/Order/1', 'name' => '#1001', 'test' => false, 'cancelledAt' => null, 'presentmentCurrencyCode' => 'USD', 'taxesIncluded' => false], 'lineItems' => ['nodes' => [$this->item(1)], 'pageInfo' => ['hasNextPage' => false]]];
    }

    private function item(int $id): array
    {
        return ['id' => 'gid://shopify/FulfillmentOrderLineItem/'.$id, 'remainingQuantity' => 2, 'totalQuantity' => 10, 'requiresShipping' => true, 'lineItem' => ['discountedUnitPriceAfterAllDiscountsSet' => ['presentmentMoney' => ['amount' => '0.1', 'currencyCode' => 'USD']]]];
    }
}
