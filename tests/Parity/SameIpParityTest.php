<?php

declare(strict_types=1);

use App\Domain\Reports\SameIpAnalyzer;
use PHPUnit\Framework\TestCase;

final class SameIpParityTest extends TestCase
{
    /** Clusters orders by client IP shared across 2+ distinct emails -- a fraud-ring signal. */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            // 3 distinct emails share IP 1.1.1.1 -> flagged
            $this->order(1, 'a@example.com', '1.1.1.1'),
            $this->order(2, 'b@example.com', '1.1.1.1'),
            $this->order(3, 'c@example.com', '1.1.1.1'),
            // same email reusing an IP twice -> counts once toward email_count (dedup)
            $this->order(4, 'a@example.com', '1.1.1.1'),
            // single email on its own IP -> not flagged
            $this->order(5, 'd@example.com', '2.2.2.2'),
            // missing IP -> excluded entirely
            $this->order(6, 'e@example.com', ''),
        ];

        $legacyMethod = new ReflectionMethod(\OrderPolicyPageLoader::class, 'buildSameIpRows');
        $legacyRows = $legacyMethod->invoke(null, $orders);

        $laravelRows = (new SameIpAnalyzer())->analyze($orders);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $email, string $clientIp): array
    {
        return ['id' => $id, 'name' => "#{$id}", 'email' => $email, 'client_ip' => $clientIp, 'created_at' => '2026-01-01T00:00:00Z', 'total_price' => '50.00', 'fulfillment_status' => 'unfulfilled'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{ip: mixed, email_count: mixed, order_count: mixed, emails: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'ip' => $r['ip'],
            'email_count' => $r['email_count'],
            'order_count' => $r['order_count'],
            'emails' => $r['emails'],
        ], $rows);
    }
}
