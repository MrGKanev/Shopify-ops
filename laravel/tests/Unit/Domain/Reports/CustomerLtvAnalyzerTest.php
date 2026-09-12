<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\CustomerLtvAnalyzer;
use PHPUnit\Framework\TestCase;

class CustomerLtvAnalyzerTest extends TestCase
{
    public function test_it_builds_top_customers_and_monthly_cohorts(): void
    {
        $orders = [
            $this->order(' Alice@Example.com ', 100, '2026-01-01'),
            $this->order('alice@example.com', 50, '2026-02-01'),
            $this->order('bob@example.com', 75, '2026-01-15'),
            $this->order('bob@example.com', 999, '2026-01-20', '2026-01-21'),
            $this->order('', 500, '2026-01-01'),
        ];

        $result = (new CustomerLtvAnalyzer)->analyze($orders);

        $this->assertSame(2, $result['total_customers']);
        $this->assertSame(225.0, $result['total_revenue']);
        $this->assertSame(['alice@example.com', 'bob@example.com'], array_column($result['top_customers'], 'email'));
        $this->assertSame(['customers' => 2, 'repeat_buyers' => 1, 'orders' => 3, 'retention_rate' => 50.0, 'average_orders' => 1.5], array_intersect_key($result['cohorts'][0], array_flip(['customers', 'repeat_buyers', 'orders', 'retention_rate', 'average_orders'])));
    }

    /** @return array<string, mixed> */
    private function order(string $email, float $total, string $createdAt, ?string $cancelledAt = null): array
    {
        return ['email' => $email, 'total_price' => $total, 'created_at' => $createdAt, 'cancelled_at' => $cancelledAt];
    }
}
