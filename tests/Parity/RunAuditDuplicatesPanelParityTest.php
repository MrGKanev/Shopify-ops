<?php

declare(strict_types=1);

use App\Domain\Reports\DuplicateOrderClusterer;
use PHPUnit\Framework\TestCase;

final class RunAuditDuplicatesPanelParityTest extends TestCase
{
    /**
     * Legacy `Comparator::findDuplicates()` (`src/Comparator.php`) powers the
     * Run Audit page's inline "N potential duplicates detected" panel: group
     * by email+rounded-amount, then slide a 24h window over created_at-sorted
     * orders within each group, keeping only sub-clusters of 2+, returned
     * newest-first (the group built ascending, then `array_reverse()`d).
     * `DuplicateOrderClusterer::cluster()` must match exactly — this is a
     * different tool from `dupes`/`DuplicateOrderAnalyzer` (10-minute window,
     * pair-based), which the audit's systemic-finding note already
     * disambiguates from this one.
     */
    public function test_clusters_match_legacy(): void
    {
        $orders = [
            // Two orders same email/amount 10 minutes apart -> one cluster of 2.
            $this->order('1', 'a@example.com', '50.00', '2026-09-01T10:00:00Z'),
            $this->order('2', 'a@example.com', '50.00', '2026-09-01T10:10:00Z'),
            // Same email/amount but 30 hours later -> starts a new window, alone -> no cluster.
            $this->order('3', 'a@example.com', '50.00', '2026-09-02T16:10:00Z'),
            // Unique order -> no cluster.
            $this->order('4', 'b@example.com', '75.00', '2026-09-01T09:00:00Z'),
        ];

        $legacyClusters = \Comparator::findDuplicates($orders);

        $laravelClusters = (new DuplicateOrderClusterer())->cluster($orders);

        $this->assertSame($this->summarize($legacyClusters), $this->summarize($laravelClusters));
    }

    /** @return array<string, mixed> */
    private function order(string $number, string $email, string $total, string $createdAt): array
    {
        return ['name' => "#{$number}", 'email' => $email, 'total_price' => $total, 'created_at' => $createdAt];
    }

    /**
     * @param  list<array{email: string, amount: string, orders: list<array<string,mixed>>}>  $clusters
     * @return list<array{email: string, amount: string, orders: list<string>}>
     */
    private function summarize(array $clusters): array
    {
        return array_map(static fn (array $cluster): array => [
            'email' => $cluster['email'],
            'amount' => $cluster['amount'],
            'orders' => array_map(static fn (array $order): string => (string) $order['name'], $cluster['orders']),
        ], $clusters);
    }
}
