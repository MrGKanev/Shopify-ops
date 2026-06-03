<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ShipStationClientTest extends TestCase
{
    private function makeStack(array $responses, array &$history = []): HandlerStack
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return $stack;
    }

    private function ss(array $responses, array &$history = []): ShipStation
    {
        return new ShipStation('key', 'secret', null, $this->makeStack($responses, $history));
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
        $orders = [['orderId' => 1, 'orderNumber' => '1001']];
        $mock   = new MockHandler([
            new Response(429, ['Retry-After' => '0']),
            $this->json(['orders' => $orders, 'pages' => 1]),
        ]);
        $ss = new ShipStation('key', 'secret', null, HandlerStack::create($mock));

        $result = $ss->findByOrderNumber('1001');

        $this->assertSame(0, $mock->count()); // both responses consumed: 429 then 200
        $this->assertSame($orders, $result);
    }

    public function testStopsRetryingAfterFiveAttempts(): void
    {
        // 6 responses: original + 5 retries, all 429 - 6th passes through and throws
        $mock = new MockHandler(array_fill(0, 6, new Response(429, ['Retry-After' => '0'], '')));
        $ss   = new ShipStation('key', 'secret', null, HandlerStack::create($mock));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/429/');

        $ss->findByOrderNumber('1001');
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
        $this->assertEqualsWithDelta(99.0, $body['amountPaid'], 0.001);
        $this->assertEqualsWithDelta(5.0, $body['shippingAmount'], 0.001);
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
