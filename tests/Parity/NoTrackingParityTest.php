<?php

declare(strict_types=1);

use App\Domain\Reports\NoTrackingAnalyzer;
use PHPUnit\Framework\TestCase;

final class NoTrackingParityTest extends TestCase
{
    /**
     * Flags fulfilled orders sitting without a tracking number past a grace
     * period — an operator relies on the `missing` list's order (oldest/most
     * overdue first) to triage which shipments to chase first, so a silently
     * different sort order here means the wrong shipment gets chased first,
     * not just a cosmetic display difference.
     */
    public function test_rows_match_legacy(): void
    {
        $now = time();
        $start = date('Y-m-d', $now - 60 * 86400);
        $end = date('Y-m-d', $now + 86400);
        $threshold = 6;

        $orders = [
            // #3001: two missing-tracking fulfillments, listed newest-first
            // (NOT already sorted by age) -- exercises whether `missing[0]`
            // (and thus this order's rank in the outer sort) is the
            // most-overdue fulfillment or just whatever came first in the
            // Shopify API response.
            $this->order(1, '3001', [
                $this->fulfillment('f-new', $now - 8 * 3600),
                $this->fulfillment('f-old', $now - 30 * 3600),
            ]),
            // #3002: single missing fulfillment, most overdue of all -> should
            // rank first regardless of the #3001 ordering question above
            $this->order(2, '3002', [$this->fulfillment('f-oldest', $now - 50 * 3600)]),
            // #3003: has tracking -> excluded
            $this->order(3, '3003', [$this->fulfillment('f-tracked', $now - 40 * 3600, 'TRACK123')]),
            // #3004: below threshold -> excluded
            $this->order(4, '3004', [$this->fulfillment('f-fresh', $now - 2 * 3600)]),
            // #3005: fulfillment created outside the date window -> excluded
            $this->order(5, '3005', [$this->fulfillment('f-outside', $now - 90 * 86400)]),
        ];

        $legacyMethod = new ReflectionMethod(\FulfillmentIssuePageLoader::class, 'buildNoTrackingRows');
        $legacyRows = $legacyMethod->invoke(null, $orders, $start, $end, $threshold);

        $laravelRows = (new NoTrackingAnalyzer())->analyze($orders, $start, $end, $threshold, $now);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function fulfillment(string $id, int $createdAtTs, string $trackingNumber = ''): array
    {
        return [
            'id' => $id,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $createdAtTs),
            'tracking_number' => $trackingNumber,
            'status' => 'success',
        ];
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $orderNumber, array $fulfillments): array
    {
        return [
            'id' => $id,
            'name' => "#{$orderNumber}",
            'email' => "order{$orderNumber}@example.com",
            'created_at' => '2026-01-01T00:00:00Z',
            'total_price' => '199.00',
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'fulfillments' => $fulfillments,
        ];
    }

    /**
     * `total` is excluded for the same cosmetic string-vs-float reason
     * documented in the other Parity tests in this directory.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, missing_ids: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'],
            'missing_ids' => array_column($r['missing'], 'id'),
        ], $rows);
    }
}
