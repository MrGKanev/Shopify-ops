<?php

declare(strict_types=1);

use App\Domain\Reports\RepeatRefundAnalyzer;
use PHPUnit\Framework\TestCase;

final class RepeatRefundParityTest extends TestCase
{
    /**
     * Flags a customer with multiple refunded orders in range -- a repeat
     * refunder is either a quality problem worth investigating or a return
     * abuse pattern, so the grouping (by email), the min-count threshold,
     * and the refunded-amount calculation (only successful `refund`-kind
     * transactions count, not pending/failed ones) all have to agree.
     */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            // a@example.com: 2 refunded orders -> meets min count of 2
            $this->order(1, '8001', 'a@example.com', '2026-01-05', [$this->successfulRefund(20.0)]),
            $this->order(2, '8002', 'A@Example.com', '2026-01-10', [$this->successfulRefund(30.0)]),
            // b@example.com: only 1 refunded order -> below threshold, excluded
            $this->order(3, '8003', 'b@example.com', '2026-01-01', [$this->successfulRefund(10.0)]),
            // c@example.com: 2 orders, but one refund transaction is pending
            // (not 'success') -> doesn't count toward refunded_amount
            $this->order(4, '8004', 'c@example.com', '2026-01-01', [$this->successfulRefund(15.0)]),
            $this->order(5, '8005', 'c@example.com', '2026-01-02', [['kind' => 'refund', 'status' => 'pending', 'amount' => '999.00']]),
        ];

        $legacyMethod = new ReflectionMethod(\OrderAnomalyPageLoader::class, 'buildRepeatRefundRows');
        $legacyRows = $legacyMethod->invoke(null, $orders, 2);

        $laravelRows = (new RepeatRefundAnalyzer())->analyze($orders, 2);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function successfulRefund(float $amount): array
    {
        return ['kind' => 'refund', 'status' => 'success', 'amount' => (string) $amount];
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $orderNumber, string $email, string $createdAt, array $transactions): array
    {
        return [
            'id' => $id,
            'name' => "#{$orderNumber}",
            'email' => $email,
            'created_at' => "{$createdAt}T00:00:00Z",
            'refunds' => [['transactions' => $transactions]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{email: mixed, refund_count: mixed, total_refunded: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'email' => $r['email'],
            'refund_count' => $r['refund_count'],
            'total_refunded' => $r['total_refunded'],
        ], $rows);
    }
}
