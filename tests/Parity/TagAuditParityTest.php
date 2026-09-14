<?php

declare(strict_types=1);

use App\Domain\Reports\TagUsageAnalyzer;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderTagInsights;

final class TagAuditParityTest extends TestCase
{
    public function test_tag_counts_latest_order_and_tie_order_match_legacy(): void
    {
        $orders = [
            ['name' => '#2', 'createdAt' => '2026-08-10T10:00:00Z', 'tags' => ['Zulu', 'Alpha', 'VIP']],
            ['name' => '#1', 'createdAt' => '2026-05-01T10:00:00Z', 'tags' => ['VIP', 'Old']],
        ];
        $client = new class($orders) extends Client
        {
            public function __construct(private readonly array $orders) {}

            public function paginateGraphQL(string $queryTemplate, string $rootKey, callable $processor, int $maxPages = 20): array
            {
                $processor(array_map(static fn (array $order): array => ['node' => $order], $this->orders));

                return ['truncated' => false, 'pages' => 1];
            }
        };
        $legacy = (new OrderTagInsights($client))->fetchTagStats('2026-01-01', '2026-12-31');
        $laravel = (new TagUsageAnalyzer)->analyze($orders, '2026-06-08');

        $this->assertSame(
            array_map(static fn (array $row): array => $row + ['orphan' => $row['count'] === 1 && $row['last_date'] < '2026-06-08'], $legacy['tags']),
            $laravel,
        );
        $this->assertSame(count($orders), $legacy['total_orders']);
    }
}
