<?php

declare(strict_types=1);

use App\Domain\Reports\FulfilledItemsAnalyzer;
use PHPUnit\Framework\TestCase;

final class FulfilledItemsParityTest extends TestCase
{
    public function test_aggregation_matches_legacy(): void
    {
        $orders = [[
            'name' => '#1001',
            'fulfillments' => [
                ['status' => 'success', 'created_at' => '2026-06-01T00:00:00Z', 'line_items' => [
                    ['title' => 'Widget', 'variant_title' => 'Blue', 'quantity' => 2],
                    ['title' => 'Plain', 'variant_title' => 'Default Title', 'quantity' => 1],
                ]],
                ['status' => 'cancelled', 'created_at' => '2026-06-15T00:00:00Z', 'line_items' => [['title' => 'Ignored', 'quantity' => 9]]],
                ['status' => 'success', 'created_at' => '2026-07-01T00:00:00Z', 'line_items' => [['title' => 'Late', 'quantity' => 9]]],
            ],
        ], [
            'name' => '#1002',
            'fulfillments' => [['status' => 'success', 'created_at' => '2026-06-30T23:59:59Z', 'line_items' => [
                ['title' => 'Widget', 'variant_title' => 'Blue', 'quantity' => 3],
            ]]],
        ]];

        $legacy = ItemizedFulfillmentReport::aggregate($orders, '2026-06-01', '2026-06-30');
        $laravel = (new FulfilledItemsAnalyzer)->analyze($orders, '2026-06-01', '2026-06-30');

        $this->assertSame(
            array_map(static fn (int $quantity, string $product): array => compact('product', 'quantity'), $legacy, array_keys($legacy)),
            $laravel,
        );
    }
}
