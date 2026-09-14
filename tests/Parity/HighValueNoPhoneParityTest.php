<?php

declare(strict_types=1);

use App\Domain\Reports\HighValueNoPhoneAnalyzer;
use PHPUnit\Framework\TestCase;

final class HighValueNoPhoneParityTest extends TestCase
{
    /**
     * Flags a high-value order with no shipping phone -- a delivery/fraud
     * risk operators want to catch before an expensive package ships with
     * no way to reach the recipient. Legacy has no currency filter at all
     * (`Laravel::analyze()`'s `$currency` parameter is a deliberate
     * net-net feature addition, tracked separately -- see
     * `docs/laravel-todo.md` -- so this fixture keeps every order in USD
     * to isolate the two other differences it's actually checking): a
     * `cancelled_at` exclusion and a sort tiebreak, neither present in
     * legacy.
     */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            // #1: high value, no phone -> flagged (baseline)
            $this->order('1', '500.00', '', '2026-01-01'),
            // #2: cancelled, high value, no phone -- legacy has no
            // cancelled-order exclusion in this report at all
            $this->order('2', '600.00', '', '2026-01-02', cancelledAt: '2026-01-03T00:00:00Z'),
            // #3 and #4: same total AND same created_at -> exercises the
            // sort tiebreak (or lack of one)
            $this->order('4', '400.00', '', '2026-01-01'),
            $this->order('3', '400.00', '', '2026-01-01'),
            // has a phone -> excluded
            $this->order('5', '500.00', '555-1234', '2026-01-01'),
            // below minimum -> excluded
            $this->order('6', '50.00', '', '2026-01-01'),
        ];

        $legacyMethod = new ReflectionMethod(\SimpleScanPageLoader::class, 'buildHvOrderRows');
        $legacyRows = $legacyMethod->invoke(null, $orders, 200.0);

        $laravelRows = (new HighValueNoPhoneAnalyzer())->analyze($orders, 200.0, 'USD');

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function order(string $orderNumber, string $totalPrice, string $phone, string $createdAt, ?string $cancelledAt = null): array
    {
        $order = [
            'id' => $orderNumber,
            'name' => "#{$orderNumber}",
            'email' => "c{$orderNumber}@example.com",
            'created_at' => "{$createdAt}T00:00:00Z",
            'total_price' => $totalPrice,
            'currency' => 'USD',
            'shipping_address' => ['phone' => $phone],
        ];
        if ($cancelledAt !== null) {
            $order['cancelled_at'] = $cancelledAt;
        }

        return $order;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): string => (string) ($r['order_number'] ?? $r['number']), $rows);
    }
}
