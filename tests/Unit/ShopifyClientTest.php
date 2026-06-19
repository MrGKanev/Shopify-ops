<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ShopifyClientTest extends TestCase
{
    private function makeStack(array $responses, array &$history = []): HandlerStack
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return $stack;
    }

    private function shopify(array $responses, array &$history = []): Shopify
    {
        return new Shopify('test.myshopify.com', 'tok_test', null, $this->makeStack($responses, $history));
    }

    private function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, array_merge(['Content-Type' => 'application/json'], $headers), json_encode($data));
    }

    // ── findByOrderNumber ─────────────────────────────────────────────────────

    public function testFindByOrderNumberReturnsOrders(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/1',
            'legacyResourceId' => '1',
            'name' => '#1001',
            'createdAt' => '2026-06-19T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'buyer@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '49.99', 'currencyCode' => 'USD']],
        ];
        $history = [];
        $shopify = $this->shopify([$this->json([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['node' => $node]],
                ],
            ],
        ])], $history);

        $result = $shopify->findByOrderNumber('1001');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame(1001, $result[0]['order_number']);
        $this->assertSame('#1001', $result[0]['name']);
        $this->assertSame('paid', $result[0]['financial_status']);
        $this->assertNull($result[0]['fulfillment_status']);
        $this->assertSame('49.99', $result[0]['total_price']);
        $this->assertStringEndsWith('/graphql.json', (string) $history[0]['request']->getUri());
    }

    public function testFindByOrderNumberStripsHash(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->json([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [],
                ],
            ],
        ])], $history);

        $shopify->findByOrderNumber('#1001');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('name:1001', $body['variables']['query']);
        $this->assertStringNotContainsString('#1001', $body['variables']['query']);
    }

    public function testFindByOrderNumberSendsAuthHeader(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->json([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [],
                ],
            ],
        ])], $history);

        $shopify->findByOrderNumber('1001');

        $this->assertSame('tok_test', $history[0]['request']->getHeaderLine('X-Shopify-Access-Token'));
    }

    // ── getOrder ──────────────────────────────────────────────────────────────

    public function testGetOrderReturnsOrder(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/42',
            'legacyResourceId' => '42',
            'name' => '#1042',
            'createdAt' => '2026-06-19T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'buyer@example.com',
            'note' => 'Leave at door',
            'tags' => ['vip', 'manual-review'],
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '79.50', 'currencyCode' => 'USD']],
            'totalTaxSet' => ['shopMoney' => ['amount' => '4.50', 'currencyCode' => 'USD']],
            'shippingAddress' => [
                'firstName' => 'Ada',
                'lastName' => 'Lovelace',
                'name' => 'Ada Lovelace',
                'company' => 'Analytical Engines',
                'address1' => '1 Main St',
                'address2' => 'Unit 2',
                'city' => 'London',
                'province' => 'England',
                'provinceCode' => 'ENG',
                'country' => 'United Kingdom',
                'countryCodeV2' => 'GB',
                'zip' => 'SW1A',
                'phone' => '123',
            ],
            'billingAddress' => null,
            'lineItems' => [
                'nodes' => [[
                    'id' => 'gid://shopify/LineItem/501',
                    'title' => 'Widget',
                    'name' => 'Widget - Red',
                    'sku' => 'WGT-RED',
                    'quantity' => 2,
                    'variantTitle' => 'Red',
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                ]],
            ],
            'shippingLines' => [
                'nodes' => [[
                    'id' => 'gid://shopify/ShippingLine/601',
                    'title' => 'Ground',
                    'code' => 'GROUND',
                    'originalPriceSet' => ['shopMoney' => ['amount' => '5.00', 'currencyCode' => 'USD']],
                ]],
            ],
        ];
        $history = [];
        $shopify = $this->shopify([$this->json(['data' => ['order' => $node]])], $history);

        $result = $shopify->getOrder('42');

        $this->assertSame(42, $result['id']);
        $this->assertSame(1042, $result['order_number']);
        $this->assertSame('#1042', $result['name']);
        $this->assertSame('paid', $result['financial_status']);
        $this->assertSame('partial', $result['fulfillment_status']);
        $this->assertSame('79.50', $result['total_price']);
        $this->assertSame('4.50', $result['total_tax']);
        $this->assertSame('vip, manual-review', $result['tags']);
        $this->assertSame('Leave at door', $result['note']);
        $this->assertSame('Ada', $result['shipping_address']['first_name']);
        $this->assertSame('GB', $result['shipping_address']['country_code']);
        $this->assertNull($result['billing_address']);
        $this->assertSame(501, $result['line_items'][0]['id']);
        $this->assertSame('WGT-RED', $result['line_items'][0]['sku']);
        $this->assertSame('25.00', $result['line_items'][0]['price']);
        $this->assertSame('Ground', $result['shipping_lines'][0]['title']);
        $this->assertSame('5.00', $result['shipping_lines'][0]['price']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('gid://shopify/Order/42', $body['variables']['id']);
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
        $page1Orders = [
            [
                'id' => 'gid://shopify/Order/1',
                'legacyResourceId' => '1',
                'name' => '#1001',
                'createdAt' => '2024-01-01T10:00:00Z',
                'cancelledAt' => null,
                'email' => 'a@example.com',
                'displayFinancialStatus' => 'PAID',
                'displayFulfillmentStatus' => 'UNFULFILLED',
                'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
                'lineItems' => ['nodes' => []],
                'shippingLines' => ['nodes' => []],
            ],
            [
                'id' => 'gid://shopify/Order/2',
                'legacyResourceId' => '2',
                'name' => '#1002',
                'createdAt' => '2024-01-02T10:00:00Z',
                'cancelledAt' => null,
                'email' => 'b@example.com',
                'displayFinancialStatus' => 'PAID',
                'displayFulfillmentStatus' => 'FULFILLED',
                'totalPriceSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'USD']],
                'lineItems' => ['nodes' => []],
                'shippingLines' => ['nodes' => []],
            ],
        ];
        $page2Orders = [[
            'id' => 'gid://shopify/Order/3',
            'legacyResourceId' => '3',
            'name' => '#1003',
            'createdAt' => '2024-01-03T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'c@example.com',
            'displayFinancialStatus' => 'PARTIALLY_REFUNDED',
            'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '30.00', 'currencyCode' => 'USD']],
            'lineItems' => [
                'nodes' => [[
                    'id' => 'gid://shopify/LineItem/3001',
                    'title' => 'Widget',
                    'name' => 'Widget - Blue',
                    'sku' => 'WGT-BLU',
                    'quantity' => 1,
                    'variantTitle' => 'Blue',
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                ]],
            ],
            'shippingLines' => [
                'nodes' => [[
                    'id' => 'gid://shopify/ShippingLine/4001',
                    'title' => 'Ground',
                    'code' => 'GROUND',
                    'originalPriceSet' => ['shopMoney' => ['amount' => '5.00', 'currencyCode' => 'USD']],
                ]],
            ],
        ]];

        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                        'edges' => array_map(fn($node) => ['node' => $node], $page1Orders),
                    ],
                ],
            ]),
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => array_map(fn($node) => ['node' => $node], $page2Orders),
                    ],
                ],
            ]),
        ], $history);

        $result = $shopify->fetchAllOrders('2024-01-01', '2024-01-31');

        $this->assertCount(3, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame(3, $result[2]['id']);
        $this->assertSame('partial', $result[2]['fulfillment_status']);
        $this->assertSame('WGT-BLU', $result[2]['line_items'][0]['sku']);
        $this->assertSame('Ground', $result[2]['shipping_lines'][0]['title']);

        $firstBody = json_decode((string) $history[0]['request']->getBody(), true);
        $secondBody = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('status:any created_at:>=2024-01-01T00:00:00Z created_at:<=2024-01-31T23:59:59Z', $firstBody['variables']['query']);
        $this->assertNull($firstBody['variables']['after']);
        $this->assertSame('cursor-1', $secondBody['variables']['after']);
        $this->assertStringEndsWith('/graphql.json', (string) $history[0]['request']->getUri());
    }

    public function testFetchAllOrdersStopsWhenNoNextPage(): void
    {
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [[
                            'node' => [
                                'id' => 'gid://shopify/Order/1',
                                'legacyResourceId' => '1',
                                'name' => '#1001',
                                'createdAt' => '2024-01-01T10:00:00Z',
                                'cancelledAt' => null,
                                'email' => 'a@example.com',
                                'displayFinancialStatus' => 'PAID',
                                'displayFulfillmentStatus' => 'UNFULFILLED',
                                'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
                                'lineItems' => ['nodes' => []],
                                'shippingLines' => ['nodes' => []],
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $result = $shopify->fetchAllOrders('2024-01-01', '2024-01-31');

        $this->assertCount(1, $result);
    }

    // ── 429 retry ─────────────────────────────────────────────────────────────

    public function testRetryOn429WithRetryAfterHeader(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'legacyResourceId' => '1',
            'name' => '#1001',
            'createdAt' => '2026-06-19T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'buyer@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
        ];
        $mock  = new MockHandler([
            new Response(429, ['Retry-After' => '0']),
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [['node' => $order]],
                    ],
                ],
            ]),
        ]);
        $shopify = new Shopify('test.myshopify.com', 'tok_test', null, HandlerStack::create($mock));

        $result = $shopify->findByOrderNumber('1001');

        $this->assertSame(0, $mock->count()); // both responses consumed: 429 then 200
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('#1001', $result[0]['name']);
    }

    public function testStopsRetryingAfterFiveAttempts(): void
    {
        // 6 responses: original + 5 retries, all 429 - 6th passes through and throws
        $mock    = new MockHandler(array_fill(0, 6, new Response(429, ['Retry-After' => '0'], '')));
        $shopify = new Shopify('test.myshopify.com', 'tok_test', null, HandlerStack::create($mock));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/429/');

        $shopify->findByOrderNumber('1001');
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

    public function testFetchOrdersForSlaRequestsFulfillmentFields(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->json(['orders' => []])], $history);

        $shopify->fetchOrdersForSla('2026-06-01', '2026-06-19');

        $uri = urldecode((string) $history[0]['request']->getUri());
        $this->assertStringContainsString('financial_status=paid,partially_paid', $uri);
        $this->assertStringContainsString('fulfillments', $uri);
        $this->assertStringContainsString('shipping_lines', $uri);
    }

    public function testFetchOrdersForDiscountAuditRequestsDiscountCodes(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->json(['orders' => []])], $history);

        $shopify->fetchOrdersForDiscountAudit('2026-06-01', '2026-06-19');

        $uri = urldecode((string) $history[0]['request']->getUri());
        $this->assertStringContainsString('discount_codes', $uri);
        $this->assertStringContainsString('shipping_address', $uri);
    }

    public function testFetchCancelledOrdersUsesCancelledStatus(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->json(['orders' => []])], $history);

        $shopify->fetchCancelledOrders('2026-06-01', '2026-06-19');

        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('status=cancelled', $uri);
    }

    public function testFetchAllProductsUsesGraphQLAndNormalizesRestShape(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'products' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [[
                            'node' => [
                                'id' => 'gid://shopify/Product/101',
                                'legacyResourceId' => '101',
                                'title' => 'Widget',
                                'status' => 'ACTIVE',
                                'descriptionHtml' => '<p>Strong widget</p>',
                                'vendor' => 'ACME',
                                'productType' => 'Tools',
                                'mediaCount' => ['count' => 1],
                                'variants' => [
                                    'edges' => [[
                                        'node' => [
                                            'id' => 'gid://shopify/ProductVariant/301',
                                            'legacyResourceId' => '301',
                                            'title' => 'Default Title',
                                            'sku' => 'WGT-1',
                                            'barcode' => '12345',
                                            'inventoryQuantity' => 7,
                                            'inventoryPolicy' => 'DENY',
                                            'inventoryItem' => ['tracked' => true],
                                        ],
                                    ]],
                                ],
                            ],
                        ]],
                    ],
                ],
            ]),
        ], $history);

        $products = $shopify->fetchAllProducts();

        $this->assertCount(1, $products);
        $this->assertSame(101, $products[0]['id']);
        $this->assertSame('active', $products[0]['status']);
        $this->assertSame('<p>Strong widget</p>', $products[0]['body_html']);
        $this->assertSame('Tools', $products[0]['product_type']);
        $this->assertCount(1, $products[0]['images']);
        $this->assertSame(301, $products[0]['variants'][0]['id']);
        $this->assertSame(101, $products[0]['variants'][0]['product_id']);
        $this->assertSame('WGT-1', $products[0]['variants'][0]['sku']);
        $this->assertSame(7, $products[0]['variants'][0]['inventory_quantity']);
        $this->assertSame('deny', $products[0]['variants'][0]['inventory_policy']);
        $this->assertSame('shopify', $products[0]['variants'][0]['inventory_management']);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/graphql.json', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('products(first: 250, query: "status:active"', $body['query']);
    }
}
