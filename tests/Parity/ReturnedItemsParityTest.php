<?php

declare(strict_types=1);

use App\Domain\Reports\ReturnedItemsAnalyzer;
use PHPUnit\Framework\TestCase;

final class ReturnedItemsParityTest extends TestCase
{
    /**
     * Aggregates returned-item quantities by product name within a date
     * range, filtered by each refund's own `created_at` (not the order's) --
     * so an old order refunded recently still counts, and a refund outside
     * the window on an in-range order doesn't.
     */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            $this->order([
                $this->refund('2026-01-10', [$this->lineItem('Widget', 2)]),
                // outside the range -> excluded
                $this->refund('2026-02-01', [$this->lineItem('Widget', 5)]),
            ]),
            $this->order([
                $this->refund('2026-01-15', [$this->lineItem('Widget', 1), $this->lineItem('Gadget', 3)]),
            ]),
        ];

        $legacyMethod = new ReflectionMethod(\ReturnedItemsReport::class, 'aggregate');
        $legacyTotals = $legacyMethod->invoke(null, $orders, '2026-01-01', '2026-01-31');

        $laravelRows = (new ReturnedItemsAnalyzer())->analyze($orders, '2026-01-01', '2026-01-31');

        $this->assertSame($legacyTotals, array_column($laravelRows, 'quantity', 'product'));
    }

    /** @return array<string, mixed> */
    private function order(array $refunds): array
    {
        return ['refunds' => $refunds];
    }

    /** @return array<string, mixed> */
    private function refund(string $createdAt, array $lineItems): array
    {
        return ['created_at' => "{$createdAt}T00:00:00Z", 'refund_line_items' => $lineItems];
    }

    /** @return array<string, mixed> */
    private function lineItem(string $name, int $quantity): array
    {
        return ['quantity' => $quantity, 'line_item' => ['name' => $name]];
    }
}
