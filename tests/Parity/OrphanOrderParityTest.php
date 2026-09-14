<?php

declare(strict_types=1);

use App\Domain\Reports\OrphanOrderAnalyzer;
use PHPUnit\Framework\TestCase;

final class OrphanOrderParityTest extends TestCase
{
    /**
     * Flags a ShipStation order with no matching Shopify order at all --
     * treated as a data-integrity problem worth investigating. A false
     * positive here sends ops chasing an order that actually exists; a
     * false negative hides a real orphan. Both directions hinge on the
     * matching index being built from the same field priority legacy uses.
     */
    public function test_rows_match_legacy(): void
    {
        $shopifyOrders = [
            // order_number and name disagree -- exercises which field the
            // matching index is built from
            $this->shopifyOrder(1, orderNumber: '1234', name: '#9999'),
            $this->shopifyOrder(2, orderNumber: '2000', name: '#2000'),
        ];

        $ssOrders = [
            // matches Shopify's order_number field (1234), not its name (9999)
            $this->ssOrder('S1', '1234'),
            // matches on the agreeing pair -> not an orphan
            $this->ssOrder('S2', '2000'),
            // matches nothing -> a real orphan
            $this->ssOrder('S3', '5555555'),
        ];

        $legacyMethod = new ReflectionMethod(\OrderAnomalyPageLoader::class, 'buildOrphanRows');
        $legacyRows = $legacyMethod->invoke(null, $ssOrders, $shopifyOrders);

        $laravelRows = (new OrphanOrderAnalyzer())->analyze($ssOrders, $shopifyOrders);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function shopifyOrder(int $id, string $orderNumber, string $name): array
    {
        return ['id' => $id, 'order_number' => $orderNumber, 'name' => $name];
    }

    /** @return array<string, mixed> */
    private function ssOrder(string $ssOrderId, string $orderNumber): array
    {
        return ['orderId' => $ssOrderId, 'orderNumber' => $orderNumber, 'orderStatus' => 'shipped', 'orderDate' => '2026-01-01T00:00:00Z', 'shipTo' => ['name' => 'Customer'], 'customerEmail' => 'c@example.com', 'orderTotal' => 50.0];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function summarize(array $rows): array
    {
        return array_column($rows, 'ss_order_id');
    }
}
