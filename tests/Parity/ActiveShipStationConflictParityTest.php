<?php

declare(strict_types=1);

use App\Domain\Reports\ActiveShipStationConflictAnalyzer;
use PHPUnit\Framework\TestCase;

final class ActiveShipStationConflictParityTest extends TestCase
{
    /**
     * Flags a refunded/cancelled Shopify order that's still active
     * (awaiting shipment/payment or on hold) in ShipStation -- a live
     * shipping-side conflict that needs a human to stop the shipment or
     * reconcile the refund. Legacy matches on `order_number` OR `name`
     * (two independent index lookups, first match wins), not a single
     * combined field, so the fixture exercises exactly that: an order
     * where only one of the two fields resolves to an active SS match.
     *
     * `buildActiveSsConflictRows()` is only ever called by legacy with
     * already refunded/cancelled orders (pre-filtered by the caller's
     * `fetchRefundedOrders()`/`fetchCancelledOrders()` GraphQL queries,
     * which are out of this harness's scope) -- so this fixture, matching
     * that real contract, only feeds it orders that are already refunded,
     * unlike `ActiveShipStationConflictAnalyzer::analyze()`, which
     * duplicates that filter inline since it receives the unfiltered order
     * set directly.
     */
    public function test_rows_match_legacy(): void
    {
        $shopifyOrders = [
            // refunded, and its order_number (not name) matches an active SS
            // order -- exercises the order_number lookup specifically
            $this->shopifyOrder(1, orderNumber: '1234', name: '#9999', financialStatus: 'refunded'),
            // refunded, both fields agree -> baseline
            $this->shopifyOrder(2, orderNumber: '2000', name: '#2000', financialStatus: 'refunded'),
        ];

        $activeSs = [
            // matches order #1's order_number (1234), not its name (9999)
            $this->ssOrder('S1', '1234', 'awaiting_shipment'),
            $this->ssOrder('S2', '2000', 'on_hold'),
        ];

        $legacyMethod = new ReflectionMethod(\OrderPolicyPageLoader::class, 'buildActiveSsConflictRows');
        $legacyRows = $legacyMethod->invoke(null, $shopifyOrders, $activeSs);

        $laravel = (new ActiveShipStationConflictAnalyzer())->analyze($shopifyOrders, $activeSs);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravel['rows']));
    }

    /** @return array<string, mixed> */
    private function shopifyOrder(int $id, string $orderNumber, string $name, string $financialStatus): array
    {
        return ['id' => $id, 'order_number' => $orderNumber, 'name' => $name, 'financial_status' => $financialStatus, 'email' => 'c@example.com', 'total_price' => '50.00', 'created_at' => '2026-01-01T00:00:00Z'];
    }

    /** @return array<string, mixed> */
    private function ssOrder(string $ssOrderId, string $orderNumber, string $status): array
    {
        return ['orderId' => $ssOrderId, 'orderNumber' => $orderNumber, 'orderStatus' => $status, 'orderDate' => '2026-01-01T00:00:00Z', 'orderTotal' => 50.0];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{shopify_id: mixed, ss_order_id: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'shopify_id' => (string) $r['shopify_id'],
            'ss_order_id' => (string) $r['ss_order_id'],
        ], $rows);
    }
}
