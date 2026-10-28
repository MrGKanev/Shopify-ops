<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\BuildsShopifyAdminClient;
use Tests\TestCase;

class ShopifyOrderEventsClientTest extends TestCase
{
    use BuildsShopifyAdminClient;

    public function test_returns_normalized_order_events_with_a_graphql_order_id(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [[
                        'node' => [
                            '__typename' => 'BasicEvent',
                            'id' => 'gid://shopify/OrderEvent/987',
                            'action' => 'CONFIRMED',
                            'appTitle' => 'Shopify',
                            'createdAt' => '2026-09-05T12:30:00Z',
                            'message' => 'Order confirmed',
                            'subjectId' => 'gid://shopify/Order/123',
                            'subjectType' => 'ORDER',
                        ],
                    ]],
                ]]],
            ]),
        ]);

        $events = $this->client()->getOrderEvents($this->store(), '123');

        $this->assertSame('confirmed', $events[0]['action']);
        $this->assertSame(123, $events[0]['subject_id']);
        $this->assertSame('Order confirmed', $events[0]['message']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://acme.myshopify.com/admin/api/2026-07/graphql.json'
            && $request['variables'] === ['id' => 'gid://shopify/Order/123', 'after' => null]
            && str_contains((string) $request['query'], 'events(first: 250, sortKey: CREATED_AT, reverse: true, after: $after)')
            && str_contains((string) $request['query'], '... on BasicEvent'));
    }

    public function test_accepts_an_already_full_graphql_order_id_unchanged(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['order' => ['events' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'edges' => [],
        ]]]])]);

        $this->client()->getOrderEvents($this->store(), 'gid://shopify/Order/123');

        Http::assertSent(fn (Request $request): bool => $request['variables']['id'] === 'gid://shopify/Order/123');
    }

    public function test_paginates_order_events_and_preserves_newest_first_api_order(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'event-cursor-1'],
                    'edges' => [['node' => [
                        'id' => 'gid://shopify/OrderEvent/2',
                        'action' => 'PAID',
                        'createdAt' => '2026-09-05T12:30:00Z',
                        'message' => 'Payment processed',
                    ]]],
                ]]]])
                ->push(['data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['node' => [
                        'id' => 'gid://shopify/OrderEvent/1',
                        'action' => 'CONFIRMED',
                        'createdAt' => '2026-09-05T12:00:00Z',
                        'message' => 'Order confirmed',
                    ]]],
                ]]]]),
        ]);

        $events = $this->client()->getOrderEvents($this->store(), 'gid://shopify/Order/123');

        $this->assertSame(['Payment processed', 'Order confirmed'], array_column($events, 'message'));
        $this->assertSame([
            ['id' => 'gid://shopify/Order/123', 'after' => null],
            ['id' => 'gid://shopify/Order/123', 'after' => 'event-cursor-1'],
        ], Http::recorded()->map(
            fn (array $record): array => $record[0]->data()['variables'],
        )->all());
        Http::assertSentCount(2);
    }

    public function test_returns_no_events_when_the_shopify_order_does_not_exist(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => null],
            ]),
        ]);

        $events = $this->client()->getOrderEvents($this->store(), '999999');

        $this->assertSame([], $events);
        Http::assertSentCount(1);
    }

    public function test_throws_when_the_order_events_connection_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => []],
            ]),
        ]);

        try {
            $this->client()->getOrderEvents($this->store(), '123');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order events returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_an_order_event_edge_is_malformed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['unexpected' => []]],
                ]]],
            ]),
        ]);

        try {
            $this->client()->getOrderEvents($this->store(), '123');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order events returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_order_events_claim_another_page_without_a_cursor(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => null],
                    'edges' => [],
                ]]],
            ]),
        ]);

        try {
            $this->client()->getOrderEvents($this->store(), '123');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order events returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_order_event_pagination_repeats_a_cursor(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'stuck-cursor'],
                    'edges' => [],
                ]]]])
                ->push(['data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'stuck-cursor'],
                    'edges' => [],
                ]]]]),
        ]);

        try {
            $this->client()->getOrderEvents($this->store(), '123');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order events returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_rejects_an_invalid_order_id_before_requesting_events(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);

        $this->client()->getOrderEvents($this->store(), '123 OR id:*');
    }
}
