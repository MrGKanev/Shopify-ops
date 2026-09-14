<?php

declare(strict_types=1);

use App\Domain\Reports\ShippedUnfulfilledAnalyzer;
use PHPUnit\Framework\TestCase;

final class SsShippedUnfulfilledParityTest extends TestCase
{
    /**
     * Flags a ShipStation-shipped order whose Shopify side still shows
     * unfulfilled -- a sync failure between the two systems that needs a
     * human to reconcile. Matching the ShipStation order to the right
     * Shopify order is the entire point of this report, so which order
     * number field wins when a Shopify order carries both `name` and
     * `order_number` matters: a false non-match hides a real sync failure,
     * a false match points ops at the wrong order.
     */
    public function test_rows_match_legacy(): void
    {
        $shopifyOrders = [
            // order_number and name disagree -- exercises which field the
            // matching index is built from
            $this->shopifyOrder(1, orderNumber: '1234', name: '#9999', fulfillmentStatus: 'unfulfilled'),
            // normal case, both fields agree -> baseline, no ambiguity
            $this->shopifyOrder(2, orderNumber: '2000', name: '#2000', fulfillmentStatus: 'unfulfilled'),
            // fulfilled -> excluded even though shipped in SS
            $this->shopifyOrder(3, orderNumber: '3000', name: '#3000', fulfillmentStatus: 'fulfilled'),
        ];

        $ssOrders = [
            // SS orderNumber matches Shopify's order_number field (1234),
            // not its name field (9999)
            $this->ssOrder('S1', '1234', 'shipped'),
            $this->ssOrder('S2', '2000', 'shipped'),
            $this->ssOrder('S3', '3000', 'shipped'),
            // not shipped -> excluded
            $this->ssOrder('S4', '2000', 'awaiting_shipment'),
            // no matching Shopify order -> excluded
            $this->ssOrder('S5', '9999999', 'shipped'),
        ];

        $legacyMethod = new ReflectionMethod(\FulfillmentIssuePageLoader::class, 'buildSsShippedUnfulfilledRows');
        $legacyRows = $legacyMethod->invoke(null, $ssOrders, $shopifyOrders);

        $laravel = (new ShippedUnfulfilledAnalyzer())->analyze($ssOrders, $shopifyOrders);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravel['rows']));
    }

    /** @return array<string, mixed> */
    private function shopifyOrder(int $id, string $orderNumber, string $name, string $fulfillmentStatus): array
    {
        return ['id' => $id, 'order_number' => $orderNumber, 'name' => $name, 'fulfillment_status' => $fulfillmentStatus, 'financial_status' => 'paid'];
    }

    /** @return array<string, mixed> */
    private function ssOrder(string $ssOrderId, string $orderNumber, string $status): array
    {
        return ['orderId' => $ssOrderId, 'orderNumber' => $orderNumber, 'orderStatus' => $status, 'orderDate' => '2026-01-01T00:00:00Z', 'shipTo' => ['name' => 'Customer'], 'customerEmail' => 'c@example.com', 'orderTotal' => 50.0];
    }

    /**
     * `shopify_id` is cast to string: legacy echoes the raw Shopify `id`
     * value (int), Laravel's `text()` helper always stringifies it — a
     * type-shape difference, not a matching bug, same precedent as other
     * rows' field-shape footnotes.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{ss_order_id: mixed, shopify_id: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'ss_order_id' => $r['ss_order_id'],
            'shopify_id' => (string) $r['shopify_id'],
        ], $rows);
    }
}
