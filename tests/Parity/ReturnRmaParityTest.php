<?php

declare(strict_types=1);

use App\Domain\Reports\ReturnRmaAnalyzer;
use PHPUnit\Framework\TestCase;

final class ReturnRmaParityTest extends TestCase
{
    /**
     * One row per refund transaction (not per order -- an order refunded
     * twice yields two rows), plus a per-SKU return-rate rollup used to
     * spot a SKU with an outsized return rate. The rollup's third field is
     * named `orders` in legacy and `events` in Laravel, but both count
     * refund *occurrences* (not distinct orders) the same way -- confirmed
     * with a fixture where the same SKU is refunded across two separate
     * refund transactions on the same order.
     */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            $this->order(1, '1001', [
                // two separate refund transactions on the same order
                $this->refund('2026-01-05', 25.0, 'Wrong size', [$this->refundLineItem('WIDGET', 1, 25.0)]),
                $this->refund('2026-01-10', 10.0, 'Defective', [$this->refundLineItem('WIDGET', 1, 10.0)]),
            ]),
            $this->order(2, '1002', [
                // one refund with two distinct SKUs
                $this->refund('2026-01-06', 40.0, '', [
                    $this->refundLineItem('WIDGET', 1, 20.0),
                    $this->refundLineItem('GADGET', 2, 20.0),
                ]),
            ]),
            // no refunds at all -> contributes nothing
            $this->order(3, '1003', []),
        ];

        $legacyMethod = new ReflectionMethod(\SimpleScanPageLoader::class, 'buildReturnRows');
        [$legacyRows, $legacySkuStat] = $legacyMethod->invoke(null, $orders);

        $laravel = (new ReturnRmaAnalyzer())->analyze($orders);

        $this->assertSame($this->summarizeRows($legacyRows), $this->summarizeRows($laravel['rows']));
        $this->assertSame($this->summarizeSkuStat($legacySkuStat), $this->summarizeSkuStat($laravel['sku_stats']));
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $orderNumber, array $refunds): array
    {
        return ['id' => $id, 'name' => "#{$orderNumber}", 'email' => "c{$orderNumber}@example.com", 'created_at' => '2026-01-01T00:00:00Z', 'financial_status' => 'refunded', 'refunds' => $refunds];
    }

    /** @return array<string, mixed> */
    private function refund(string $createdAt, float $totalRefunded, string $note, array $lineItems): array
    {
        return ['created_at' => "{$createdAt}T00:00:00Z", 'total_refunded' => $totalRefunded, 'note' => $note, 'refund_line_items' => $lineItems];
    }

    /** @return array<string, mixed> */
    private function refundLineItem(string $sku, int $quantity, float $subtotal): array
    {
        return ['quantity' => $quantity, 'subtotal' => $subtotal, 'line_item' => ['sku' => $sku, 'name' => $sku]];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, refund_date: mixed, refund_total: mixed}>
     */
    private function summarizeRows(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'],
            'refund_date' => $r['refund_date'],
            'refund_total' => $r['refund_total'],
        ], $rows);
    }

    /**
     * @param list<array<string, mixed>> $stats
     * @return list<array{sku: mixed, units: mixed, occurrences: mixed, revenue: mixed}>
     */
    private function summarizeSkuStat(array $stats): array
    {
        return array_map(static fn (array $s): array => [
            'sku' => $s['sku'],
            'units' => $s['units'],
            'occurrences' => $s['orders'] ?? $s['events'],
            'revenue' => $s['revenue'],
        ], $stats);
    }
}
