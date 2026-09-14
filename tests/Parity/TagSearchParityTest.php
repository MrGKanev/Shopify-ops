<?php

declare(strict_types=1);

use App\Application\Orders\SearchOrdersByTag;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderTagInsights;

final class TagSearchParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_matching_orders_and_pagination_metadata_match_legacy(): void
    {
        $raw = [['legacyResourceId' => 42, 'name' => '#1001', 'createdAt' => '2026-06-01T10:00:00Z', 'tags' => ['VIP Member']]];
        $client = new class($raw) extends Client
        {
            public function __construct(private readonly array $orders) {}

            public function paginateGraphQL(string $queryTemplate, string $rootKey, callable $processor, int $maxPages = 20): array
            {
                $processor(array_map(static fn (array $order): array => ['node' => $order], $this->orders));

                return ['truncated' => true, 'pages' => 2];
            }
        };
        $legacy = (new OrderTagInsights($client))->searchOrdersByTag('VIP Member', '2026-06-01', '2026-06-30');
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('searchOrdersByTag')->once()->andReturn(['orders' => [[
            'id' => 42, 'order_number' => '1001', 'name' => '#1001', 'created_at' => '2026-06-01T10:00:00Z',
            'tags' => ['VIP Member'], 'financial_status' => '', 'fulfillment_status' => '', 'total_price' => '', 'currency' => '',
        ]], 'pages' => 2, 'truncated' => true]);
        $laravel = (new SearchOrdersByTag($gateway))->handle(new Store, 'VIP Member', '2026-06-01', '2026-06-30');

        $this->assertSame(array_map('strval', array_column($legacy['matches'], 'legacyResourceId')), array_column($laravel->orders, 'id'));
        $this->assertSame($legacy['pages'], $laravel->pages);
        $this->assertSame($legacy['truncated'], $laravel->truncated);
    }
}
