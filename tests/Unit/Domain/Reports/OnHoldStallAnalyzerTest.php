<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\OnHoldStallAnalyzer;
use PHPUnit\Framework\TestCase;

class OnHoldStallAnalyzerTest extends TestCase
{
    public function test_it_calculates_waiting_days_surfaces_first_hold_and_sorts(): void
    {
        $now = strtotime('2026-06-20T10:00:00Z');
        $rows = (new OnHoldStallAnalyzer)->analyze([
            $this->node('#RECENT', '2026-06-18T10:00:00Z', []),
            $this->node('#OLD', '2026-06-01T10:00:00Z', [['reason' => 'FRAUD_RISK', 'reasonNotes' => 'Review'], ['reason' => 'MANUAL']]),
            $this->node('#UNKNOWN', ''),
        ], $now);

        $this->assertSame(['#OLD', '#RECENT', '#UNKNOWN'], array_column($rows, 'order_number'));
        $this->assertSame([19, 2, 0], array_column($rows, 'days_waiting'));
        $this->assertSame(['FRAUD_RISK', 'Review'], [$rows[0]['hold_reason'], $rows[0]['hold_notes']]);
        $this->assertSame(['', ''], [$rows[1]['hold_reason'], $rows[1]['hold_notes']]);
    }

    private function node(string $name, string $createdAt, array $holds = []): array
    {
        return ['order' => ['legacyResourceId' => '1', 'name' => $name, 'createdAt' => $createdAt, 'email' => 'a@example.com', 'totalPriceSet' => ['shopMoney' => ['amount' => '12.50']], 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'ON_HOLD'], 'fulfillmentHolds' => $holds];
    }
}
