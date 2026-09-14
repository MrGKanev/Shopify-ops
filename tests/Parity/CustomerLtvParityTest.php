<?php

declare(strict_types=1);

use App\Domain\Reports\CustomerLtvAnalyzer;
use PHPUnit\Framework\TestCase;

final class CustomerLtvParityTest extends TestCase
{
    /**
     * Top-100-by-LTV list and monthly first-order cohort table with
     * repeat-buyer retention rate. `total_orders` is computed one layer up
     * on the Laravel side (`RunCustomerLtvReport::handle()` via
     * `count($result['orders'])`) instead of inside the analyzer, but from
     * the same raw order count as legacy's `count($orders)` -- not a
     * divergence, just architected at a different layer.
     */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            $this->order('a@example.com', '25.00', '2026-01-05'),
            $this->order('a@example.com', '15.00', '2026-01-20'), // repeat buyer, same cohort month
            $this->order('b@example.com', '100.00', '2026-01-10'), // single-order customer, highest LTV
            $this->order('c@example.com', '10.00', '2026-02-01'), // different cohort month
            // cancelled -> excluded entirely
            $this->order('d@example.com', '9999.00', '2026-01-01', cancelledAt: '2026-01-02T00:00:00Z'),
            // no email -> excluded
            $this->order('', '50.00', '2026-01-01'),
        ];

        $legacyMethod = new ReflectionMethod(\CustomerLTVPageLoader::class, 'build');
        $legacyResult = $legacyMethod->invoke(null, $orders, '2026-01-01', '2026-02-28');

        $laravelResult = (new CustomerLtvAnalyzer())->analyze($orders);

        $this->assertSame($this->summarizeTop($legacyResult['top_customers']), $this->summarizeTop($laravelResult['top_customers']));
        $this->assertSame($this->summarizeCohort($legacyResult['cohort']), $this->summarizeCohort($laravelResult['cohorts']));
        $this->assertSame($legacyResult['total_customers'], $laravelResult['total_customers']);
        $this->assertSame($legacyResult['total_revenue'], $laravelResult['total_revenue']);
    }

    /** @return array<string, mixed> */
    private function order(string $email, string $totalPrice, string $createdAt, ?string $cancelledAt = null): array
    {
        $order = ['email' => $email, 'total_price' => $totalPrice, 'created_at' => "{$createdAt}T00:00:00Z"];
        if ($cancelledAt !== null) {
            $order['cancelled_at'] = $cancelledAt;
        }

        return $order;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{email: mixed, orders: mixed, total: mixed, average: mixed, first_date: mixed, last_date: mixed}>
     */
    private function summarizeTop(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'email' => $r['email'],
            'orders' => $r['orders'],
            'total' => $r['total'],
            'average' => $r['avg'] ?? $r['average'],
            'first_date' => $r['first_date'],
            'last_date' => $r['last_date'],
        ], $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{month: mixed, new_customers: mixed, repeat: mixed, retention_rate: mixed, avg_orders: mixed}>
     */
    private function summarizeCohort(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'month' => $r['month'],
            'new_customers' => $r['new'] ?? $r['customers'],
            'repeat' => $r['repeat'] ?? $r['repeat_buyers'],
            'retention_rate' => $r['retention_rate'],
            'avg_orders' => $r['avg_orders'] ?? $r['average_orders'],
        ], $rows);
    }
}
