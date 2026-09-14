<?php

declare(strict_types=1);

use App\Domain\Reports\OnHoldStallAnalyzer;
use PHPUnit\Framework\TestCase;

final class OnHoldStallParityTest extends TestCase
{
    /** One row per on-hold fulfillment order, sorted by days_waiting descending. */
    public function test_rows_match_legacy(): void
    {
        $now = time();

        $nodes = [
            $this->node(1, '1001', $now - 10 * 86400, 'Fraud review', 'Manual hold'),
            $this->node(2, '1002', $now - 3 * 86400, 'Awaiting stock', ''),
            $this->node(3, '1003', $now - 20 * 86400, 'Address issue', 'Needs confirmation'),
        ];

        $legacyMethod = new ReflectionMethod(\FulfillmentIssuePageLoader::class, 'buildOnHoldStallRows');
        $legacyRows = $legacyMethod->invoke(null, $nodes, $now);

        $laravelRows = (new OnHoldStallAnalyzer())->analyze($nodes, $now);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function node(int $id, string $orderNumber, int $createdAtTs, string $reason, string $notes): array
    {
        return [
            'order' => [
                'legacyResourceId' => $id,
                'name' => "#{$orderNumber}",
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $createdAtTs),
                'email' => "c{$orderNumber}@example.com",
                'totalPriceSet' => ['shopMoney' => ['amount' => '50.00']],
                'displayFinancialStatus' => 'PAID',
                'displayFulfillmentStatus' => 'ON_HOLD',
            ],
            'fulfillmentHolds' => [['reason' => $reason, 'reasonNotes' => $notes]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, days_waiting: mixed, hold_reason: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'],
            'days_waiting' => $r['days_waiting'],
            'hold_reason' => $r['hold_reason'],
        ], $rows);
    }
}
