<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ApiHealth.php';

use PHPUnit\Framework\TestCase;

class ApiHealthTest extends TestCase
{
    public function testCheckShopifyUsesGraphQLForShopAndScopes(): void
    {
        $requests = [];
        $result = ApiHealth::checkShopify('example.myshopify.com', 'tok_test', function (string $url, array $headers, ?array $jsonBody = null) use (&$requests): array {
            $requests[] = compact('url', 'headers', 'jsonBody');

            return [
                'ok' => true,
                'code' => 200,
                'ms' => 12,
                'error' => '',
                'headers' => ['x-shopify-api-version' => Shopify::API_VERSION],
                'json' => [
                    'data' => [
                        'shop' => ['name' => 'Example Shop'],
                        'currentAppInstallation' => [
                            'accessScopes' => [
                                ['handle' => 'read_orders'],
                                ['handle' => 'read_fulfillments'],
                            ],
                        ],
                    ],
                ],
            ];
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('Example Shop', $result['shop_name']);
        $this->assertSame(Shopify::API_VERSION, $result['returned_version']);
        $this->assertSame(['read_orders', 'read_fulfillments'], $result['scopes']);
        $this->assertSame([], $result['missing_scopes']);
        $this->assertArrayHasKey('graphql', $result['checks']);

        $this->assertCount(1, $requests);
        $this->assertSame(
            'https://example.myshopify.com/admin/api/' . Shopify::API_VERSION . '/graphql.json',
            $requests[0]['url']
        );
        $this->assertContains('X-Shopify-Access-Token: tok_test', $requests[0]['headers']);
        $this->assertStringContainsString('shop { name }', $requests[0]['jsonBody']['query']);
        $this->assertStringContainsString('currentAppInstallation', $requests[0]['jsonBody']['query']);
        $this->assertStringContainsString('accessScopes { handle }', $requests[0]['jsonBody']['query']);
    }

    public function testCheckShopifyReportsMissingScopesFromGraphQLScopes(): void
    {
        $result = ApiHealth::checkShopify('example.myshopify.com', 'tok_test', fn() => [
            'ok' => true,
            'code' => 200,
            'ms' => 10,
            'error' => '',
            'headers' => ['x-shopify-api-version' => Shopify::API_VERSION],
            'json' => [
                'data' => [
                    'shop' => ['name' => 'Example Shop'],
                    'currentAppInstallation' => [
                        'accessScopes' => [
                            ['handle' => 'read_orders'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(['read_fulfillments'], $result['missing_scopes']);
    }
}
