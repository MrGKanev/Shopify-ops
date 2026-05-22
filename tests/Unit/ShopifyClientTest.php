<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ShopifyClientTest extends TestCase
{
    private function makeClient(array $responses, array &$history = []): Client
    {
        $mock    = new MockHandler($responses);
        $stack   = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return new Client(['handler' => $stack]);
    }

    private function shopify(array $responses, array &$history = []): Shopify
    {
        return new Shopify('test.myshopify.com', 'tok_test', null, $this->makeClient($responses, $history));
    }

    private function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, array_merge(['Content-Type' => 'application/json'], $headers), json_encode($data));
    }

    // ── findByOrderNumber ─────────────────────────────────────────────────────

    public function testFindByOrderNumberReturnsOrders(): void
    {
        $orders  = [['id' => 1, 'name' => '#1001']];
        $history = [];
        $shopify = $this->shopify([$this->json(['orders' => $orders])], $history);

        $result = $shopify->findByOrderNumber('1001');

        $this->assertSame($orders, $result);
        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('name=1001', $uri);
    }

    public function testFindByOrderNumberStripsHash(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->json(['orders' => []])], $history);

        $shopify->findByOrderNumber('#1001');

        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('name=1001', $uri);
        $this->assertStringNotContainsString('name=%231001', $uri);
    }

    public function testFindByOrderNumberSendsAuthHeader(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->json(['orders' => []])], $history);

        $shopify->findByOrderNumber('1001');

        $this->assertSame('tok_test', $history[0]['request']->getHeaderLine('X-Shopify-Access-Token'));
    }

    // ── getOrder ──────────────────────────────────────────────────────────────

    public function testGetOrderReturnsOrder(): void
    {
        $order   = ['id' => 42, 'name' => '#1042'];
        $shopify = $this->shopify([$this->json(['order' => $order])]);

        $result = $shopify->getOrder('42');

        $this->assertSame($order, $result);
    }

    public function testGetOrderThrowsOn404(): void
    {
        $shopify = $this->shopify([$this->json(['errors' => 'Not found'], 404)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/404/');

        $shopify->getOrder('99999');
    }

    // ── fetchAllOrders pagination ─────────────────────────────────────────────

    public function testFetchAllOrdersPaginates(): void
    {
        $page1Orders = [['id' => 1], ['id' => 2]];
        $page2Orders = [['id' => 3]];

        $nextUrl = 'https://test.myshopify.com/admin/api/2025-04/orders.json?page_info=abc';
        $link    = "<{$nextUrl}>; rel=\"next\"";

        $shopify = $this->shopify([
            $this->json(['orders' => $page1Orders], 200, ['Link' => $link]),
            $this->json(['orders' => $page2Orders]),
        ]);

        $result = $shopify->fetchAllOrders('2024-01-01', '2024-01-31');

        $this->assertCount(3, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame(3, $result[2]['id']);
    }

    public function testFetchAllOrdersStopsWhenNoLinkHeader(): void
    {
        $shopify = $this->shopify([
            $this->json(['orders' => [['id' => 1]]]),
        ]);

        $result = $shopify->fetchAllOrders('2024-01-01', '2024-01-31');

        $this->assertCount(1, $result);
    }

    // ── 429 retry ─────────────────────────────────────────────────────────────

    public function testRetryOn429WithRetryAfterHeader(): void
    {
        $order   = ['id' => 1, 'name' => '#1001'];
        $history = [];

        $shopify = $this->shopify([
            new Response(429, ['Retry-After' => '0']),
            $this->json(['orders' => [$order]]),
        ], $history);

        $result = $shopify->findByOrderNumber('1001');

        $this->assertCount(2, $history);
        $this->assertSame(429, $history[0]['response']->getStatusCode());
        $this->assertSame(200, $history[1]['response']->getStatusCode());
        $this->assertSame([$order], $result);
    }

    // ── GraphQL (searchOrdersByTag) ───────────────────────────────────────────

    public function testSearchOrdersByTagReturnsMatches(): void
    {
        $node = ['id' => 'gid://shopify/Order/1', 'name' => '#1001', 'tags' => ['wholesale']];

        $gqlResponse = [
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges'    => [['node' => $node]],
                ],
            ],
        ];

        $shopify = $this->shopify([$this->json($gqlResponse)]);
        $result  = $shopify->searchOrdersByTag('wholesale');

        $this->assertCount(1, $result['matches']);
        $this->assertSame('#1001', $result['matches'][0]['name']);
        $this->assertFalse($result['truncated']);
    }

    public function testSearchOrdersByTagThrowsOnGraphQLErrors(): void
    {
        $shopify = $this->shopify([$this->json(['errors' => [['message' => 'Syntax error']]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/GraphQL/');

        $shopify->searchOrdersByTag('test');
    }

    // ── isOnHold ─────────────────────────────────────────────────────────────

    public function testIsOnHoldReturnsTrueWhenOnHold(): void
    {
        $shopify = $this->shopify([
            $this->json(['fulfillment_orders' => [['status' => 'on_hold']]]),
        ]);

        $this->assertTrue($shopify->isOnHold('123'));
    }

    public function testIsOnHoldReturnsFalseWhenOpen(): void
    {
        $shopify = $this->shopify([
            $this->json(['fulfillment_orders' => [['status' => 'open']]]),
        ]);

        $this->assertFalse($shopify->isOnHold('123'));
    }
}
