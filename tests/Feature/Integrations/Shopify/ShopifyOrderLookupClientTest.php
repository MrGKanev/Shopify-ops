<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\BuildsShopifyAdminClient;
use Tests\TestCase;

class ShopifyOrderLookupClientTest extends TestCase
{
    use BuildsShopifyAdminClient;

    public function test_finds_an_order_number_and_returns_the_legacy_order_shape(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'orders' => [
                        'edges' => [[
                            'node' => [
                                'id' => 'gid://shopify/Order/123456789',
                                'legacyResourceId' => '123456789',
                                'name' => '#65075',
                                'createdAt' => '2026-08-30T10:15:00Z',
                                'cancelledAt' => null,
                                'email' => 'buyer@example.com',
                                'displayFinancialStatus' => 'PAID',
                                'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
                                'totalPriceSet' => ['shopMoney' => ['amount' => '129.90', 'currencyCode' => 'EUR']],
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $orders = $this->client()->findByOrderNumber($this->store(), '#65075');

        $this->assertSame([[
            'id' => 123456789,
            'order_number' => 65075,
            'name' => '#65075',
            'created_at' => '2026-08-30T10:15:00Z',
            'cancelled_at' => null,
            'email' => 'buyer@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'partial',
            'total_price' => '129.90',
            'admin_graphql_api_id' => 'gid://shopify/Order/123456789',
            'currency' => 'EUR',
        ]], $orders);
        Http::assertSent(fn (Request $request): bool => $request['variables'] === ['query' => 'name:65075']
            && str_contains((string) $request['query'], 'query FindOrderByName')
            && str_contains((string) $request['query'], 'shippingAddress')
            && str_contains((string) $request['query'], 'billingAddress')
            && str_contains((string) $request['query'], 'note')
            && str_contains((string) $request['query'], 'tags')
            && str_contains((string) $request['query'], 'lineItems(first: 250)')
            && str_contains((string) $request['query'], 'fulfillments(first: 250)')
            && str_contains((string) $request['query'], 'processedAt')
            && str_contains((string) $request['query'], 'closedAt')
            && str_contains((string) $request['query'], 'cancelReason')
            && str_contains((string) $request['query'], 'risk {')
            && str_contains((string) $request['query'], 'assessments { riskLevel }')
            && str_contains((string) $request['query'], 'fulfillmentLineItems(first: 250)')
            && str_contains((string) $request['query'], 'transactions(first: 250)'));
    }

    public function test_finds_no_order_returns_an_empty_array(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['edges' => []]]])]);

        $orders = $this->client()->findByOrderNumber($this->store(), '99999999');

        $this->assertSame([], $orders);
    }

    public function test_empty_batch_returns_without_an_external_request(): void
    {
        Http::preventStrayRequests();

        $empty = $this->client()->findByOrderNumbers($this->store(), []);
        $blank = $this->client()->findByOrderNumbers($this->store(), ['', '  ', '#']);

        $this->assertSame([], $empty);
        $this->assertSame([], $blank);
        Http::assertNothingSent();
    }

    public function test_batch_lookup_deduplicates_inputs_and_keys_exact_matches_and_misses(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [
                        ['node' => [
                            'id' => 'gid://shopify/Order/1',
                            'legacyResourceId' => '1',
                            'name' => '#1001',
                            'createdAt' => '2026-09-01T10:00:00Z',
                            'cancelledAt' => null,
                            'email' => 'buyer@example.com',
                            'tags' => ['vip'],
                            'displayFinancialStatus' => 'PAID',
                            'displayFulfillmentStatus' => 'UNFULFILLED',
                            'totalPriceSet' => ['shopMoney' => ['amount' => '10.00']],
                            'shippingAddress' => [
                                'address1' => '1 Main Street',
                                'country' => 'Bulgaria',
                                'countryCodeV2' => 'BG',
                                'phone' => '+359888123456',
                            ],
                            'billingAddress' => [
                                'country' => 'Bulgaria',
                                'countryCodeV2' => 'BG',
                            ],
                            'risk' => [
                                'recommendation' => 'CANCEL',
                                'assessments' => [['riskLevel' => 'HIGH']],
                            ],
                        ]],
                        ['node' => [
                            'id' => 'gid://shopify/Order/999',
                            'legacyResourceId' => '999',
                            'name' => '#10010',
                        ]],
                    ],
                ]],
            ]),
        ]);

        $result = $this->client()->findByOrderNumbers(
            $this->store(),
            ['#1001', '1001', ' #1002 ', ''],
        );

        $this->assertSame([1001, 1002], array_keys($result));
        $this->assertSame('#1001', $result['1001'][0]['name']);
        $this->assertSame(['vip'], $result['1001'][0]['tags']);
        $this->assertSame('BG', $result['1001'][0]['shipping_address']['country_code']);
        $this->assertSame('BG', $result['1001'][0]['billing_address']['country_code']);
        $this->assertSame('HIGH', $result['1001'][0]['risk_level']);
        $this->assertSame([], $result['1002']);
        Http::assertSent(fn (Request $request): bool => $request['variables'] === [
            'query' => '(name:1001 OR name:1002)',
            'after' => null,
        ]
            && str_contains((string) $request['query'], 'query FindOrdersByNames')
            && str_contains((string) $request['query'], 'tags')
            && str_contains((string) $request['query'], 'shippingAddress')
            && str_contains((string) $request['query'], 'billingAddress')
            && str_contains((string) $request['query'], 'risk {')
            && ! str_contains((string) $request['query'], 'lineItems'));
    }

    public function test_batch_lookup_preserves_multiple_exact_matches_as_ambiguous(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [
                        ['node' => ['id' => 'gid://shopify/Order/2', 'name' => '#1001']],
                        ['node' => ['id' => 'gid://shopify/Order/1', 'name' => '#1001']],
                    ],
                ]],
            ]),
        ]);

        $result = $this->client()->findByOrderNumbers($this->store(), ['1001']);

        $this->assertSame([2, 1], array_column($result['1001'], 'id'));
        Http::assertSentCount(1);
    }

    public function test_batch_lookup_follows_the_orders_cursor(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'orders-cursor-1'],
                    'edges' => [['node' => ['id' => 'gid://shopify/Order/1', 'name' => '#1001']]],
                ]]])
                ->push(['data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['node' => ['id' => 'gid://shopify/Order/2', 'name' => '#1002']]],
                ]]]),
        ]);

        $result = $this->client()->findByOrderNumbers($this->store(), ['1001', '1002']);

        $this->assertSame('#1001', $result['1001'][0]['name']);
        $this->assertSame('#1002', $result['1002'][0]['name']);
        $this->assertSame([
            ['query' => '(name:1001 OR name:1002)', 'after' => null],
            ['query' => '(name:1001 OR name:1002)', 'after' => 'orders-cursor-1'],
        ], Http::recorded()->map(
            fn (array $record): array => $record[0]->data()['variables'],
        )->all());
        Http::assertSentCount(2);
    }

    public function test_batch_lookup_throws_instead_of_returning_truncated_matches(): void
    {
        Http::preventStrayRequests();
        $requestCount = 0;
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => function () use (&$requestCount): mixed {
                $requestCount++;

                return Http::response(['data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => "cursor-{$requestCount}"],
                    'edges' => [],
                ]]]);
            },
        ]);

        try {
            $this->client()->findByOrderNumbers($this->store(), ['1001']);
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify batch order lookup exceeded its page limit.', $exception->getMessage());
        }

        Http::assertSentCount(20);
    }

    public function test_batch_lookup_rejects_a_malformed_returned_name(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['node' => ['id' => 'gid://shopify/Order/1', 'name' => ['#1001']]]],
                ]],
            ]),
        ]);

        try {
            $this->client()->findByOrderNumbers($this->store(), ['1001']);
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify batch order lookup returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_batch_lookup_rejects_invalid_input_before_an_external_request(): void
    {
        Http::preventStrayRequests();

        try {
            $this->client()->findByOrderNumbers($this->store(), ['1001', '1002 OR status:any']);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A Shopify order number is invalid.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_batch_lookup_rejects_a_non_string_member_before_an_external_request(): void
    {
        Http::preventStrayRequests();

        try {
            /** @phpstan-ignore argument.type */
            $this->client()->findByOrderNumbers($this->store(), ['1001', ['1002']]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Every Shopify order number must be a string.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_batch_lookup_rejects_more_than_fifty_unique_numbers_before_an_external_request(): void
    {
        Http::preventStrayRequests();
        $orderNumbers = array_map(
            fn (int $number): string => (string) $number,
            range(1001, 1051),
        );

        try {
            $this->client()->findByOrderNumbers($this->store(), $orderNumbers);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Shopify batch lookup accepts at most 50 unique order numbers.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_fetches_every_line_item_page_before_normalizing_an_order(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['orders' => ['edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/Order/123',
                        'name' => '#65075',
                        'lineItems' => [
                            'nodes' => [[
                                'id' => 'gid://shopify/LineItem/1',
                                'sku' => 'FIRST-SKU',
                                'quantity' => 1,
                            ]],
                            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'line-cursor-1'],
                        ],
                    ],
                ]]]]])
                ->push(['data' => ['order' => ['lineItems' => [
                    'nodes' => [[
                        'id' => 'gid://shopify/LineItem/2',
                        'sku' => 'SECOND-SKU',
                        'quantity' => 2,
                    ]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'line-cursor-2'],
                ]]]]),
        ]);

        $orders = $this->client()->findByOrderNumber($this->store(), '65075');

        $this->assertSame(['FIRST-SKU', 'SECOND-SKU'], array_column($orders[0]['line_items'], 'sku'));
        $this->assertSame([1, 2], array_column($orders[0]['line_items'], 'quantity'));
        $this->assertSame([
            ['query' => 'name:65075'],
            ['id' => 'gid://shopify/Order/123', 'after' => 'line-cursor-1'],
        ], Http::recorded()->map(
            fn (array $record): array => $record[0]->data()['variables'],
        )->all());
        Http::assertSentCount(2);
    }

    public function test_throws_when_a_followup_line_item_page_is_malformed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['orders' => ['edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/Order/123',
                        'name' => '#65075',
                        'lineItems' => [
                            'nodes' => [],
                            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'line-cursor-1'],
                        ],
                    ],
                ]]]]])
                ->push(['data' => ['order' => ['lineItems' => ['nodes' => []]]]]),
        ]);

        try {
            $this->client()->findByOrderNumber($this->store(), '65075');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify line items returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }
}
