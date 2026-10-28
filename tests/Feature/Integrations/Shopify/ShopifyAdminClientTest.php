<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Integrations\Shopify\Contracts\ShopifyCustomers;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\Exceptions\ShopifyResponseException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsShopifyAdminClient;
use Tests\TestCase;

class ShopifyAdminClientTest extends TestCase
{
    use BuildsShopifyAdminClient;

    public function test_health_check_returns_shop_and_unique_access_scopes(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => [
                'shop' => ['name' => 'Acme', 'ianaTimezone' => 'America/New_York'],
                'currentAppInstallation' => ['accessScopes' => [
                    ['handle' => 'read_orders'],
                    ['handle' => 'read_fulfillments'],
                    ['handle' => 'read_orders'],
                ]],
            ]], 200, ['X-Shopify-API-Version' => '2026-07']),
        ]);

        $this->assertSame([
            'shop_name' => 'Acme',
            'timezone' => 'America/New_York',
            'scopes' => ['read_orders', 'read_fulfillments'],
            'requested_version' => '2026-07',
            'returned_version' => '2026-07',
        ], $this->client()->healthCheck($this->store()));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'currentAppInstallation')
            && str_contains((string) $request['query'], 'accessScopes { handle }'));
    }

    public function test_returns_graphql_data_with_store_authentication_and_versioned_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['shop' => ['name' => 'Acme']],
            ]),
        ]);

        $result = $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');

        $this->assertSame(['data' => ['shop' => ['name' => 'Acme']]], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://acme.myshopify.com/admin/api/2026-07/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'shpat_test-token')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_paginates_graphql_connections_with_cursor_variables(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push([
                    'data' => ['orders' => [
                        'edges' => [['node' => ['id' => 'first']]],
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                    ]],
                ])
                ->push([
                    'data' => ['orders' => [
                        'edges' => [['node' => ['id' => 'second']]],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cursor-2'],
                    ]],
                ]),
        ]);

        $result = $this->client()->paginateGraphql(
            $this->store(),
            'query Orders($after: String) { orders(first: 1, after: $after) { edges { node { id } } } }',
            'orders',
            ['status' => 'open'],
        );

        $this->assertSame([
            'edges' => [
                ['node' => ['id' => 'first']],
                ['node' => ['id' => 'second']],
            ],
            'pages' => 2,
            'truncated' => false,
        ], $result);
        $this->assertSame([
            ['status' => 'open', 'after' => null],
            ['status' => 'open', 'after' => 'cursor-1'],
        ], Http::recorded()->map(
            fn (array $record): array => $record[0]->data()['variables'],
        )->all());
        Http::assertSentCount(2);
    }

    public function test_throws_on_an_unexpected_order_lookup_shape(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => ['nodes' => []]],
            ]),
        ]);

        try {
            $this->client()->findByOrderNumber($this->store(), '65075');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order lookup returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_on_a_malformed_order_lookup_edge_without_returning_partial_results(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => ['edges' => [
                    ['node' => ['name' => '#65075']],
                    ['unexpected' => []],
                ]]],
            ]),
        ]);

        try {
            $this->client()->findByOrderNumber($this->store(), '65075');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order lookup returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_marks_graphql_pagination_as_truncated_at_the_page_limit(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => [
                    'edges' => [['node' => ['id' => 'first']]],
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                ]],
            ]),
        ]);

        $result = $this->client()->paginateGraphql(
            $this->store(),
            'query Orders($after: String) { orders(first: 1, after: $after) { edges { node { id } } } }',
            'orders',
            maxPages: 1,
        );

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['pages']);
        Http::assertSentCount(1);
    }

    public function test_throws_on_an_unexpected_pagination_shape(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => ['nodes' => []]],
            ]),
        ]);

        $this->expectException(ShopifyGraphqlException::class);

        $this->client()->paginateGraphql(
            $this->store(),
            'query Orders($after: String) { orders(first: 1, after: $after) { edges { node { id } } } }',
            'orders',
        );
    }

    public function test_throws_the_graphql_errors_returned_in_a_successful_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'errors' => [['message' => 'Access denied']],
            ]),
        ]);

        try {
            $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame([['message' => 'Access denied']], $exception->errors());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_graphql_returns_neither_data_nor_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['extensions' => []]),
        ]);

        try {
            $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify GraphQL returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_graphql_returns_invalid_json(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(
                'not-json',
                headers: ['Content-Type' => 'application/json'],
            ),
        ]);

        try {
            $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify GraphQL returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_get_returns_invalid_json(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/webhooks.json' => Http::response(
                'not-json',
                headers: ['Content-Type' => 'application/json'],
            ),
        ]);

        try {
            $this->client()->get($this->store(), 'webhooks.json');
            $this->fail('Expected ShopifyResponseException was not thrown.');
        } catch (ShopifyResponseException $exception) {
            $this->assertSame('Shopify returned an invalid JSON response.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_on_a_4xx_get_without_retrying(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/webhooks.json?limit=250' => Http::response([], 401),
        ]);

        try {
            $this->client()->get($this->store(), 'webhooks.json', ['limit' => 250]);
            $this->fail('Expected IntegrationException was not thrown.');
        } catch (IntegrationException $exception) {
            $this->assertSame(401, $exception->status);
        }

        Http::assertSentCount(1);
    }

    public function test_retries_transient_get_responses_and_returns_the_successful_payload(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/webhooks.json' => Http::sequence()
                ->pushStatus(500)
                ->pushStatus(429)
                ->push(['webhooks' => [['id' => 42]]]),
        ]);

        $result = $this->client()->get($this->store(), 'webhooks.json');

        $this->assertSame(['webhooks' => [['id' => 42]]], $result);
        Http::assertSentCount(3);
        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(),
            Sleep::for(500)->milliseconds(),
        ]);
    }

    public function test_retries_a_failed_get_connection(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/webhooks.json' => Http::sequence()
                ->pushFailedConnection()
                ->push(['webhooks' => []]),
        ]);

        $result = $this->client()->get($this->store(), 'webhooks.json');

        $this->assertSame(['webhooks' => []], $result);
        Http::assertSentCount(2);
        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(),
        ]);
    }

    public function test_retries_a_graphql_query_after_a_server_error(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->pushStatus(500)
                ->push(['data' => ['shop' => ['name' => 'Unexpected retry']]]),
        ]);

        $result = $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');

        $this->assertSame('Unexpected retry', $result['data']['shop']['name']);
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(100)->milliseconds()]);
    }

    #[DataProvider('invalidResourcePaths')]
    public function test_rejects_resource_paths_that_can_escape_the_versioned_api_base(string $resource): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);

        $this->client()->get($this->store(), $resource);
    }

    public function test_rejects_an_order_number_that_can_change_the_search_query(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);

        $this->client()->findByOrderNumber($this->store(), '65075 OR status:any');
    }

    public function test_rejects_a_missing_access_token(): void
    {
        Http::preventStrayRequests();
        $store = Store::factory()->make([
            'shopify_store' => 'acme',
            'shopify_access_token' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->client()->graphql($store, 'query ShopName { shop { name } }');
    }

    public function test_capability_interfaces_resolve_through_the_gateway_binding(): void
    {
        $this->assertInstanceOf(ShopifyAdminClient::class, app(ShopifyOrders::class));

        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        foreach ([ShopifyOrders::class, ShopifyCatalog::class, ShopifyCustomers::class, ShopifyPayments::class] as $capability) {
            $this->assertSame($gateway, app($capability));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidResourcePaths(): array
    {
        return [
            'absolute URL' => ['https://attacker.example/credentials'],
            'parent traversal' => ['../credentials'],
            'nested parent traversal' => ['webhooks/../../credentials'],
            'protocol-relative URL' => ['//attacker.example/credentials'],
        ];
    }
}
