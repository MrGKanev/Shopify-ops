<?php

declare(strict_types=1);

use App\Domain\Reports\ShippingMarginAnalyzer;
use PHPUnit\Framework\TestCase;

final class ShippingMarginParityTest extends TestCase
{
    /**
     * This report exists to catch orders that shipped at a real dollar loss
     * (label + insurance cost exceeding what the customer was charged for
     * shipping), so the thing worth protecting here is the money math itself
     * — legacy `Comparator::shippingLoss()` — and which shipments survive
     * the threshold filter. Both sides route through the same formula
     * (`shipCost = shipmentCost + insuranceCost`, `loss = shipCost -
     * shippingCharged`), the same voided-shipment exclusion, and the same
     * loss-descending sort, so this asserts they still agree row-for-row and
     * in the same order, not just that the two functions compile.
     *
     * `total` and `ss_url` are deliberately excluded from the comparison:
     * legacy keeps `total_price` as whatever type it received (often a
     * string, e.g. `"50.00"`), while Laravel casts it to `float` — same
     * numeric value, different PHP type, so `assertSame` would flag a
     * non-issue. `ss_url` differs only in `urlencode()` vs `rawurlencode()`,
     * which is unobservable for the numeric ShipStation order IDs both sides
     * actually receive. Neither affects the loss calculation or which rows
     * appear.
     */
    public function test_rows_and_carrier_summary_match_legacy(): void
    {
        $shipments = [
            // matches order 1001, loses $5 -> above threshold, included
            $this->shipment('1001', 10.00, 0.00, 'usps', 'priority', '2026-01-01T00:00:00Z', '555'),
            // matches order 1002, loses $18 -> above threshold, included
            $this->shipment('1002', 21.00, 0.00, 'fedex', 'ground', '2026-01-02T00:00:00Z', '556'),
            // matches order 1003, loses only $0.50 -> below the $1 threshold, excluded
            $this->shipment('1003', 5.50, 0.00, 'usps', 'priority', '2026-01-03T00:00:00Z', '557'),
            // voided shipment -> excluded regardless of cost
            $this->shipment('1004', 50.00, 0.00, 'ups', 'ground', '2026-01-04T00:00:00Z', '558', voided: true),
            // no matching Shopify order -> excluded
            $this->shipment('9999', 99.00, 0.00, 'ups', 'ground', '2026-01-05T00:00:00Z', '559'),
            // second usps loss, for the by-carrier aggregation
            $this->shipment('1005', 15.00, 2.00, 'usps', 'priority', '2026-01-06T00:00:00Z', '560'),
        ];

        $orders = [
            $this->order('gid1', '1001', 5.00),
            $this->order('gid2', '1002', 3.00),
            $this->order('gid3', '1003', 5.00),
            $this->order('gid4', '1004', 0.00),
            $this->order('gid5', '1005', 7.00),
        ];

        $legacyMethod = new ReflectionMethod(\FulfillmentIssuePageLoader::class, 'buildShippingMarginRows');
        $legacyRows = $legacyMethod->invoke(null, $shipments, $orders, 1.0);

        $laravelRows = (new ShippingMarginAnalyzer())->analyze($shipments, $orders, 1.0);

        $this->assertSame($this->summarizeRows($legacyRows), $this->summarizeRows($laravelRows));

        $legacyCarrierMethod = new ReflectionMethod(\FulfillmentIssuePageLoader::class, 'buildShippingMarginCarrierSummary');
        $legacyCarrier = $legacyCarrierMethod->invoke(null, $legacyRows);

        $laravelCarrier = (new ShippingMarginAnalyzer())->byCarrier($laravelRows);

        $this->assertSame($legacyCarrier, $laravelCarrier);
    }

    /** @return array<string, mixed> */
    private function shipment(
        string $orderNumber,
        float $shipmentCost,
        float $insuranceCost,
        string $carrier,
        string $service,
        string $shipDate,
        string $orderId,
        bool $voided = false,
    ): array {
        return [
            'orderNumber' => $orderNumber,
            'voided' => $voided,
            'shipmentCost' => $shipmentCost,
            'insuranceCost' => $insuranceCost,
            'carrierCode' => $carrier,
            'serviceCode' => $service,
            'shipDate' => $shipDate,
            'orderId' => $orderId,
        ];
    }

    /** @return array<string, mixed> */
    private function order(string $id, string $orderNumber, float $shippingCharged): array
    {
        return [
            'id' => $id,
            'order_number' => $orderNumber,
            'name' => "#{$orderNumber}",
            'email' => "{$orderNumber}@example.com",
            'total_price' => '100.00',
            'shipping_lines' => [['price' => number_format($shippingCharged, 2, '.', '')]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: string, ship_cost: float, shipping_charged: float, loss: float, carrier: string}>
     */
    private function summarizeRows(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => (string) $r['order_number'],
            'ship_cost' => (float) $r['ship_cost'],
            'shipping_charged' => (float) $r['shipping_charged'],
            'loss' => (float) $r['loss'],
            'carrier' => (string) $r['carrier'],
        ], $rows);
    }
}
