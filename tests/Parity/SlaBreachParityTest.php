<?php

declare(strict_types=1);

use App\Domain\Orders\OrderTypeClassifier;
use App\Domain\Reports\FulfillmentSlaAnalyzer;
use PHPUnit\Framework\TestCase;

final class SlaBreachParityTest extends TestCase
{
    /**
     * Groups breaches by carrier method and region so ops can spot a
     * systemic carrier/region problem, not just a one-off late order — so
     * the `method`/`region` fields have to actually reflect what Shopify
     * sent, not silently fall back to "Unknown" when the primary field
     * (title/province_code/country_code) is absent but a usable fallback
     * (code/province/country) exists.
     */
    public function test_rows_match_legacy(): void
    {
        $now = time();
        $threshold = 3;

        $orders = [
            // #5001: no shipping line title, only a carrier code -> exercises
            // the title-missing/code-present fallback
            $this->order(1, '5001', $now - 10 * 86400, shippingLine: ['code' => 'EXPEDITED'], address: ['province_code' => 'ON', 'country_code' => 'CA']),
            // #5002: no province_code/country_code, only the full names ->
            // exercises the region fallback to province/country
            $this->order(2, '5002', $now - 10 * 86400, shippingLine: ['title' => 'Ground'], address: ['province' => 'Ontario', 'country' => 'Canada']),
            // #5003: normal case, title + codes present -> baseline, no fallback needed
            $this->order(3, '5003', $now - 10 * 86400, shippingLine: ['title' => 'Ground', 'code' => 'GRD'], address: ['province_code' => 'CA', 'country_code' => 'US']),
            // #5004: no shipping line at all, no shipping address at all ->
            // both fall back to 'Unknown' on both sides (baseline)
            $this->order(4, '5004', $now - 10 * 86400, shippingLine: null, address: null),
            // #5005: under threshold -> excluded
            $this->order(5, '5005', $now - 1 * 86400, shippingLine: ['title' => 'Ground'], address: ['country_code' => 'US']),
            // #5006: cancelled -> excluded
            $this->order(6, '5006', $now - 10 * 86400, shippingLine: ['title' => 'Ground'], address: ['country_code' => 'US'], cancelledAt: '2026-01-01T00:00:00Z'),
        ];

        $legacyMethod = new ReflectionMethod(\FulfillmentIssuePageLoader::class, 'buildSlaBreachRows');
        $legacyRows = $legacyMethod->invoke(null, $orders, $threshold, $now);

        $laravelRows = (new FulfillmentSlaAnalyzer(new OrderTypeClassifier()))->analyze($orders, $threshold, $now);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function order(
        int $id,
        string $orderNumber,
        int $createdAtTs,
        ?array $shippingLine,
        ?array $address,
        ?string $cancelledAt = null,
    ): array {
        $order = [
            'id' => $id,
            'name' => "#{$orderNumber}",
            'email' => "order{$orderNumber}@example.com",
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $createdAtTs),
            'total_price' => '199.00',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'fulfillments' => [],
            'shipping_lines' => $shippingLine === null ? [] : [$shippingLine],
            'shipping_address' => $address,
        ];
        if ($cancelledAt !== null) {
            $order['cancelled_at'] = $cancelledAt;
        }

        return $order;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, method: mixed, region: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'],
            'method' => $r['method'],
            'region' => $r['region'],
        ], $rows);
    }
}
