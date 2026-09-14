<?php

declare(strict_types=1);

use App\Domain\Orders\OrderTypeClassifier;
use App\Domain\Reports\ShipmentAgingAnalyzer;
use PHPUnit\Framework\TestCase;

final class ShipmentAgingParityTest extends TestCase
{
    /**
     * Flags ShipStation's awaiting-shipment queue for orders stuck past a
     * threshold, with `by_sku`/`by_type` rollups so ops can spot a stuck SKU
     * or bundle type, not just individual stale orders — so the rollup
     * aggregation (oldest_days as a running max, per-group order count) has
     * to agree, not just the row-level filter.
     */
    public function test_rows_match_legacy(): void
    {
        $now = time();
        $threshold = 3;

        $orders = [
            // #6001: Z1 grinder, 10 days old -> included, contributes to
            // Z1's order_type rollup and the ZERNO-Z1-BLACK SKU rollup
            $this->order('6001', $now - 10 * 86400, [$this->item('ZERNO-Z1-BLACK', 1)]),
            // #6002: same SKU as #6001, 5 days old -> SKU rollup's oldest_days
            // stays 10 (running max), but orders count goes to 2
            $this->order('6002', $now - 5 * 86400, [$this->item('ZERNO-Z1-BLACK', 2)]),
            // #6003: different SKU, 20 days old -> its own SKU rollup, becomes
            // the oldest overall (tests the rows-level sort too)
            $this->order('6003', $now - 20 * 86400, [$this->item('WIDGET', 1)]),
            // #6004: under threshold -> excluded entirely, no rollup contribution
            $this->order('6004', $now - 1 * 86400, [$this->item('WIDGET', 1)]),
            // #6005: unparseable date -> excluded
            $this->order('6005', null, [$this->item('WIDGET', 1)]),
        ];

        $legacyMethod = new ReflectionMethod(\FulfillmentIssuePageLoader::class, 'buildShipmentAgingData');
        [$legacyRows, $legacyBySku, $legacyByType] = $legacyMethod->invoke(null, $orders, $threshold, $now);

        $laravel = (new ShipmentAgingAnalyzer(new OrderTypeClassifier()))->analyze($orders, $threshold, $now);

        $this->assertSame($this->summarizeRows($legacyRows), $this->summarizeRows($laravel['rows']));
        $this->assertSame($legacyBySku, $laravel['by_sku']);
        $this->assertSame($legacyByType, $laravel['by_type']);
    }

    /** @return array<string, mixed> */
    private function item(string $sku, int $quantity): array
    {
        return ['sku' => $sku, 'name' => $sku, 'quantity' => $quantity];
    }

    /** @return array<string, mixed> */
    private function order(string $orderNumber, ?int $orderDateTs, array $items): array
    {
        return [
            'orderId' => $orderNumber,
            'orderNumber' => $orderNumber,
            'orderDate' => $orderDateTs === null ? 'not-a-date' : gmdate('Y-m-d\TH:i:s\Z', $orderDateTs),
            'shipTo' => ['name' => "Customer {$orderNumber}"],
            'customerEmail' => "order{$orderNumber}@example.com",
            'orderTotal' => 199.0,
            'orderStatus' => 'awaiting_shipment',
            'items' => $items,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, days: mixed, order_type: mixed, skus: mixed}>
     */
    private function summarizeRows(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'],
            'days' => $r['days'],
            'order_type' => $r['order_type'],
            'skus' => $r['skus'],
        ], $rows);
    }
}
