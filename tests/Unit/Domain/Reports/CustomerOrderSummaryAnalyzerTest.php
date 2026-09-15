<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\CustomerOrderSummaryAnalyzer;
use PHPUnit\Framework\TestCase;

class CustomerOrderSummaryAnalyzerTest extends TestCase
{
    public function test_it_summarizes_spend_statuses_currency_and_tags(): void
    {
        $result = (new CustomerOrderSummaryAnalyzer)->analyze([
            ['total_price' => '50.00', 'currency' => 'EUR', 'financial_status' => 'paid', 'cancelled_at' => null, 'tags' => ['VIP', 'Repeat']],
            ['total_price' => '25.50', 'currency' => 'EUR', 'financial_status' => 'refunded', 'cancelled_at' => '2026-01-02', 'tags' => ['VIP', '']],
        ]);

        $this->assertSame(['total_spent' => 75.5, 'currency' => 'EUR', 'paid' => 1, 'cancelled' => 1, 'tags' => ['VIP' => 2, 'Repeat' => 1]], $result);
    }
}
