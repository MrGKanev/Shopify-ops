<?php

declare(strict_types=1);

use App\Domain\Reports\DuplicateOrderAnalyzer;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\DuplicateOrderInsights;

final class DuplicateDetectorParityTest extends TestCase
{
    public function test_matched_pairs_agree_with_legacy_within_the_real_ten_minute_window(): void
    {
        $orders = [
            ['name' => '#1001', 'email' => 'jane@example.com', 'amount' => '50.00', 'created_at' => '2026-09-01T10:00:00Z'],
            ['name' => '#1002', 'email' => 'jane@example.com', 'amount' => '50.00', 'created_at' => '2026-09-01T10:10:00Z'],
            ['name' => '#1003', 'email' => 'jane@example.com', 'amount' => '50.00', 'created_at' => '2026-09-01T11:00:00Z'],
        ];

        $this->assertSame($this->legacyPairs($orders), $this->laravelPairs($orders));
    }

    /** @param list<array{name: string, email: string, amount: string, created_at: string}> $orders @return list<array{string, string}> */
    private function legacyPairs(array $orders): array
    {
        $nodes = array_map(static fn (array $o): array => [
            'id' => 'gid://shopify/Order/1',
            'legacyResourceId' => '1',
            'name' => $o['name'],
            'email' => $o['email'],
            'createdAt' => $o['created_at'],
            'displayFinancialStatus' => 'PAID',
            'totalPriceSet' => ['shopMoney' => ['amount' => $o['amount'], 'currencyCode' => 'USD']],
        ], $orders);

        $body = json_encode([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => array_map(static fn (array $node): array => ['node' => $node], $nodes),
                ],
            ],
        ]);

        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
        $client = new Client('https://example.myshopify.com/admin/api/2026-01', 'token', $stack);
        $result = (new DuplicateOrderInsights($client))->findDuplicateOrders('2026-09-01', '2026-09-02');

        return $this->pairNames($result['pairs']);
    }

    /** @param list<array{name: string, email: string, amount: string, created_at: string}> $orders @return list<array{string, string}> */
    private function laravelPairs(array $orders): array
    {
        $flat = array_map(static fn (array $o): array => [
            'name' => $o['name'],
            'email' => $o['email'],
            'total_price' => $o['amount'],
            'created_at' => $o['created_at'],
        ], $orders);

        $pairs = (new DuplicateOrderAnalyzer())->analyze($flat);

        return $this->pairNames(array_map(static fn (array $p): array => [$p['first'], $p['second']], $pairs));
    }

    /** @param list<array{0: array<string, mixed>, 1: array<string, mixed>}> $pairs @return list<array{string, string}> */
    private function pairNames(array $pairs): array
    {
        $names = array_map(static fn (array $pair): array => [$pair[0]['name'], $pair[1]['name']], $pairs);
        sort($names);

        return $names;
    }
}
