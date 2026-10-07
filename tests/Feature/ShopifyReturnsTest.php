<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyReturns;
use App\Models\Store;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyReturnsTest extends TestCase
{
    public function test_real_returns_are_loaded_for_old_orders_and_queries_are_store_scoped_and_read_only(): void
    {
        $store = Store::factory()->make(['shopify_store' => 'returns-test', 'shopify_timezone' => 'America/New_York']);
        $this->fakeReturns($store);

        $result = app(ShopifyReturns::class)->candidates($store, '2026-10-01', '2026-10-06');

        $this->assertCount(1, $result['returns']);
        $this->assertSame('gid://shopify/Return/1', $result['returns'][0]['id']);
        $this->assertSame('PROCESSING_REQUIRED', $result['returns'][0]['reverse_lines'][0]['dispositions'][0]['type']);
        $this->assertFalse($result['truncated']);
        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'], 'query ReturnExceptionOrders') && str_contains($request['variables']['search'], 'created_at:<2026-10-07T00:00:00-04:00') && ! str_contains($request['variables']['search'], 'created_at:>='));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request['query'], 'mutation'));
    }

    public function test_nested_line_item_pagination_is_completed_before_a_return_is_analyzed(): void
    {
        $store = Store::factory()->make(['shopify_store' => 'returns-test']);
        $this->fakeReturns($store, paginateLines: true);

        $result = app(ShopifyReturns::class)->candidates($store, '2026-10-01', '2026-10-06');

        $this->assertCount(2, $result['returns'][0]['return_lines']);
        $this->assertFalse($result['truncated']);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'], 'query ReturnExceptionLines') && $request['variables']['after'] === 'line-page-1');
    }

    public function test_a_return_with_truncated_nested_data_is_excluded_and_incompleteness_is_reported(): void
    {
        $store = Store::factory()->make(['shopify_store' => 'returns-test']);
        $this->fakeReturns($store, truncateLines: true);

        $result = app(ShopifyReturns::class)->candidates($store, '2026-10-01', '2026-10-06');

        $this->assertSame([], $result['returns']);
        $this->assertTrue($result['truncated']);
        Http::assertSentCount(25);
    }

    public function test_returns_outside_the_selected_creation_dates_are_not_scanned(): void
    {
        $store = Store::factory()->make(['shopify_store' => 'returns-test']);
        $this->fakeReturns($store);

        $result = app(ShopifyReturns::class)->candidates($store, '2026-10-03', '2026-10-06');

        $this->assertSame([], $result['returns']);
        Http::assertSentCount(2);
    }

    public function test_previously_flagged_returns_are_rechecked_even_after_the_order_disappears_from_active_candidates(): void
    {
        $store = Store::factory()->make(['shopify_store' => 'returns-test']);
        $this->fakeReturns($store, noCandidates: true);

        $result = app(ShopifyReturns::class)->candidates($store, '2026-10-01', '2026-10-06', ['gid://shopify/Return/1']);

        $this->assertSame('CLOSED', $result['returns'][0]['status']);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'], 'query ReturnExceptionSnapshot') && $request['variables']['id'] === 'gid://shopify/Return/1');
    }

    public function test_unconfirmed_previously_flagged_return_does_not_get_a_false_resolved_snapshot(): void
    {
        $store = Store::factory()->make(['shopify_store' => 'returns-test']);
        Http::preventStrayRequests();
        Http::fake([$this->url($store) => Http::sequence()
            ->push(['data' => ['orders' => ['edges' => [], 'pageInfo' => ['hasNextPage' => false]]]])
            ->push(['data' => ['return' => null]])]);

        $this->expectException(ShopifyGraphqlException::class);
        app(ShopifyReturns::class)->candidates($store, '2026-10-01', '2026-10-06', ['gid://shopify/Return/1']);
    }

    public function test_access_scope_errors_stop_the_scan_instead_of_producing_a_false_empty_result(): void
    {
        $store = Store::factory()->make(['shopify_store' => 'returns-test']);
        Http::preventStrayRequests();
        Http::fake([$this->url($store) => Http::sequence()
            ->push(['data' => ['orders' => ['edges' => [['node' => $this->order()]], 'pageInfo' => ['hasNextPage' => false]]]])
            ->push(['errors' => [['message' => 'Missing read_returns']]])]);

        $this->expectException(ShopifyGraphqlException::class);
        app(ShopifyReturns::class)->candidates($store, '2026-10-01', '2026-10-06');
    }

    public function test_invalid_nested_cursor_is_rejected(): void
    {
        $store = Store::factory()->make(['shopify_store' => 'returns-test']);
        Http::preventStrayRequests();
        Http::fake([$this->url($store) => Http::sequence()
            ->push(['data' => ['orders' => ['edges' => [['node' => $this->order()]], 'pageInfo' => ['hasNextPage' => false]]]])
            ->push(['data' => ['order' => ['returns' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => null]]]]])]);

        $this->expectException(ShopifyGraphqlException::class);
        app(ShopifyReturns::class)->candidates($store, '2026-10-01', '2026-10-06');
    }

    private function url(Store $store): string
    {
        return 'https://'.$store->shopify_store.'.myshopify.com/admin/api/2026-07/graphql.json';
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return ['id' => 'gid://shopify/Order/42', 'legacyResourceId' => 42, 'name' => '#1001'];
    }

    private function fakeReturns(Store $store, bool $paginateLines = false, bool $truncateLines = false, bool $noCandidates = false): void
    {
        Http::preventStrayRequests();
        $linePage = 0;
        Http::fake([$this->url($store) => function (Request $request) use ($paginateLines, $truncateLines, $noCandidates, &$linePage): PromiseInterface {
            $query = $request['query'];
            $header = ['id' => 'gid://shopify/Return/1', 'name' => 'RMA1', 'status' => 'OPEN', 'createdAt' => '2026-10-02T12:00:00Z', 'requestApprovedAt' => '2026-10-02T13:00:00Z'];
            if (str_contains($query, 'query ReturnExceptionOrders')) {
                return Http::response(['data' => ['orders' => ['edges' => $noCandidates ? [] : [['node' => $this->order()]], 'pageInfo' => ['hasNextPage' => false]]]]);
            }
            if (str_contains($query, 'query ReturnExceptionSnapshot')) {
                return Http::response(['data' => ['return' => [...$header, 'status' => 'CLOSED', 'order' => $this->order()]]]);
            }
            if (str_contains($query, 'query OrderReturns')) {
                return $this->connection('order', 'returns', [$header]);
            }
            if (str_contains($query, 'query ReturnExceptionLines')) {
                $linePage++;
                $node = ['id' => 'gid://shopify/ReturnLineItem/'.$linePage, 'quantity' => 2, 'processedQuantity' => 0, 'unprocessedQuantity' => 2, 'fulfillmentLineItem' => ['id' => 'gid://shopify/FulfillmentLineItem/1']];

                return $this->connection('return', 'returnLineItems', [$node], $truncateLines || ($paginateLines && $linePage === 1), 'line-page-'.$linePage);
            }
            if (str_contains($query, 'query ReturnExceptionExchanges')) {
                return $this->connection('return', 'exchangeLineItems', []);
            }
            if (str_contains($query, 'query ReturnExceptionReverseOrders')) {
                return $this->connection('return', 'reverseFulfillmentOrders', [['id' => 'gid://shopify/ReverseFulfillmentOrder/1']]);
            }
            if (str_contains($query, 'query ReturnExceptionDispositions')) {
                return $this->connection('reverseFulfillmentOrder', 'lineItems', [['id' => 'gid://shopify/ReverseFulfillmentOrderLineItem/1', 'totalQuantity' => 2, 'fulfillmentLineItem' => ['id' => 'gid://shopify/FulfillmentLineItem/1'], 'dispositions' => [['id' => 'gid://shopify/ReverseFulfillmentOrderDisposition/1', 'type' => 'PROCESSING_REQUIRED', 'quantity' => 2, 'createdAt' => '2026-10-02T13:00:00Z', 'location' => ['id' => 'gid://shopify/Location/1', 'name' => 'Warehouse']]]]]);
            }

            return Http::response(['errors' => [['message' => 'Unexpected query']]]);
        }]);
    }

    /** @param list<array<string, mixed>> $nodes */
    private function connection(string $root, string $field, array $nodes, bool $more = false, ?string $cursor = null): PromiseInterface
    {
        return Http::response(['data' => [$root => [$field => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $more, 'endCursor' => $cursor]]]]]);
    }
}
