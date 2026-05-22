<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ShipStationClientTest extends TestCase
{
    private function makeClient(array $responses, array &$history = []): Client
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return new Client(['handler' => $stack]);
    }

    private function ss(array $responses, array &$history = []): ShipStation
    {
        return new ShipStation('key', 'secret', null, $this->makeClient($responses, $history));
    }

    private function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    // ── Auth header ───────────────────────────────────────────────────────────

    public function testSendsBasicAuthHeader(): void
    {
        $history = [];
        $ss      = $this->ss([$this->json(['orders' => [], 'pages' => 1])], $history);

        $ss->findByOrderNumber('1001');

        $auth = $history[0]['request']->getHeaderLine('Authorization');
        $this->assertStringStartsWith('Basic ', $auth);
        $this->assertSame(base64_encode('key:secret'), substr($auth, 6));
    }

    // ── findByOrderNumber ─────────────────────────────────────────────────────

    public function testFindByOrderNumberReturnsOrders(): void
    {
        $orders = [['orderId' => 1, 'orderNumber' => '1001']];
        $ss     = $this->ss([$this->json(['orders' => $orders, 'pages' => 1])]);

        $result = $ss->findByOrderNumber('1001');

        $this->assertSame($orders, $result);
    }

    public function testFindByOrderNumberPassesQueryParam(): void
    {
        $history = [];
        $ss      = $this->ss([$this->json(['orders' => [], 'pages' => 1])], $history);

        $ss->findByOrderNumber('5555');

        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('orderNumber=5555', $uri);
    }

    // ── 429 retry ─────────────────────────────────────────────────────────────

    public function testRetryOn429(): void
    {
        $orders  = [['orderId' => 1, 'orderNumber' => '1001']];
        $history = [];

        $ss = $this->ss([
            new Response(429),
            $this->json(['orders' => $orders, 'pages' => 1]),
        ], $history);

        $result = $ss->findByOrderNumber('1001');

        $this->assertCount(2, $history);
        $this->assertSame(429, $history[0]['response']->getStatusCode());
        $this->assertSame(200, $history[1]['response']->getStatusCode());
        $this->assertSame($orders, $result);
    }

    // ── createOrder / buildPayload ────────────────────────────────────────────

    public function testCreateOrderPostsCorrectPayload(): void
    {
        $history = [];
        $ss      = $this->ss([$this->json(['orderId' => 99])], $history);

        $shopifyOrder = [
            'order_number'    => 1001,
            'created_at'      => '2024-01-15T10:00:00Z',
            'email'           => 'test@example.com',
            'total_price'     => '99.00',
            'total_tax'       => '8.00',
            'shipping_lines'  => [['price' => '5.00']],
            'line_items'      => [['id' => 1, 'title' => 'Widget', 'sku' => 'WGT-1', 'quantity' => 2, 'price' => '47.00']],
            'billing_address' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '1 Main St', 'city' => 'NY', 'zip' => '10001', 'country_code' => 'US'],
        ];

        $ss->createOrder($shopifyOrder);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('1001', $body['orderNumber']);
        $this->assertSame('test@example.com', $body['customerEmail']);
        $this->assertSame(99.0, $body['amountPaid']);
        $this->assertSame(5.0, $body['shippingAmount']);
        $this->assertCount(1, $body['items']);
        $this->assertSame('WGT-1', $body['items'][0]['sku']);
    }

    public function testBuildPayloadShippingFallsBackToBilling(): void
    {
        $ss = $this->ss([]);

        $shopifyOrder = [
            'order_number'    => 1002,
            'billing_address' => ['first_name' => 'John', 'last_name' => 'Doe', 'city' => 'LA', 'country_code' => 'US'],
        ];

        $payload = $ss->buildPayload($shopifyOrder);

        $this->assertSame('LA', $payload['shipTo']['city']);
    }

    // ── error handling ────────────────────────────────────────────────────────

    public function testThrowsOnApiError(): void
    {
        $ss = $this->ss([$this->json(['message' => 'Unauthorized'], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/401/');

        $ss->findByOrderNumber('1001');
    }

    public function testThrowsOnNonJsonResponse(): void
    {
        $ss = $this->ss([new Response(200, [], 'not json')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-JSON/');

        $ss->findByOrderNumber('1001');
    }
}
