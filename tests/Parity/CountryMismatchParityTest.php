<?php

declare(strict_types=1);

use App\Domain\Reports\CountryMismatchAnalyzer;
use PHPUnit\Framework\TestCase;

final class CountryMismatchParityTest extends TestCase
{
    /**
     * Flags a paid order where billing and shipping countries differ -- a
     * documented fraud signal. A silently-dropped row here hides that
     * signal from whoever reviews this report.
     */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            // both country_code present and differ -> flagged (baseline)
            $this->order(1, '2026-01-05', bill: ['country_code' => 'US'], ship: ['country_code' => 'CA']),
            // no country_code, only the full country name field -- exercises
            // the country_code -> country fallback
            $this->order(2, '2026-01-06', bill: ['country' => 'United States'], ship: ['country' => 'Canada']),
            // same country on both sides -> not flagged
            $this->order(3, '2026-01-01', bill: ['country_code' => 'US'], ship: ['country_code' => 'US']),
            // missing shipping country entirely -> not flagged (can't compare)
            $this->order(4, '2026-01-02', bill: ['country_code' => 'US'], ship: []),
            // cancelled, but countries still differ -- legacy has no
            // cancelled-order exclusion in this report at all
            $this->order(5, '2026-01-07', bill: ['country_code' => 'US'], ship: ['country_code' => 'CA'], cancelledAt: '2026-01-08T00:00:00Z'),
            // same created_at as order #1, to exercise the sort tiebreak (or lack of one)
            $this->order(6, '2026-01-05', bill: ['country_code' => 'US'], ship: ['country_code' => 'MX']),
        ];

        $legacyMethod = new ReflectionMethod(\SimpleScanPageLoader::class, 'buildCountryMismatchRows');
        $legacyRows = $legacyMethod->invoke(null, $orders);

        $laravel = (new CountryMismatchAnalyzer())->analyze($orders);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravel['rows']));
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $createdAt, array $bill, array $ship, ?string $cancelledAt = null): array
    {
        $order = [
            'id' => $id,
            'name' => "#{$id}",
            'email' => "c{$id}@example.com",
            'created_at' => "{$createdAt}T00:00:00Z",
            'total_price' => '50.00',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'billing_address' => $bill,
            'shipping_address' => $ship,
        ];
        if ($cancelledAt !== null) {
            $order['cancelled_at'] = $cancelledAt;
        }

        return $order;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: mixed, bill_country: mixed, ship_country: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (string) ($r['shopify_id'] ?? $r['id']),
            'bill_country' => $r['bill_country'] ?? $r['billing_country'],
            'ship_country' => $r['ship_country'] ?? $r['shipping_country'],
        ], $rows);
    }
}
