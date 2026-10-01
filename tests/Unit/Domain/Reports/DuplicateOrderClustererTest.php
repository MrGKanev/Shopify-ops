<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\DuplicateOrderClusterer;
use PHPUnit\Framework\TestCase;

class DuplicateOrderClustererTest extends TestCase
{
    public function test_it_clusters_same_email_and_rounded_amount_within_a_day_newest_first(): void
    {
        $clusters = (new DuplicateOrderClusterer)->cluster([
            $this->order('#1001', ' Buyer@Example.com ', '49.99', '2026-01-10T10:00:00Z'),
            $this->order('#1002', 'buyer@example.com', '50.01', '2026-01-10T18:00:00Z'),
        ]);

        $this->assertCount(1, $clusters);
        $this->assertSame('buyer@example.com', $clusters[0]['email']);
        $this->assertSame('50', $clusters[0]['amount']);
        $this->assertSame(['#1002', '#1001'], array_column($clusters[0]['orders'], 'name'));
    }

    public function test_a_gap_over_a_day_splits_clusters_and_the_newest_cluster_sorts_first(): void
    {
        $clusters = (new DuplicateOrderClusterer)->cluster([
            $this->order('#1001', 'buyer@example.com', '50.00', '2026-01-01T00:00:00Z'),
            $this->order('#1002', 'buyer@example.com', '50.00', '2026-01-01T06:00:00Z'),
            $this->order('#1003', 'buyer@example.com', '50.00', '2026-01-20T00:00:00Z'),
            $this->order('#1004', 'buyer@example.com', '50.00', '2026-01-20T06:00:00Z'),
        ]);

        $this->assertCount(2, $clusters);
        $this->assertSame(['#1004', '#1003'], array_column($clusters[0]['orders'], 'name'));
        $this->assertSame(['#1002', '#1001'], array_column($clusters[1]['orders'], 'name'));
    }

    public function test_each_hop_only_needs_to_be_within_a_day_so_a_chain_can_span_longer(): void
    {
        $clusters = (new DuplicateOrderClusterer)->cluster([
            $this->order('#1001', 'buyer@example.com', '50.00', '2026-01-01T00:00:00Z'),
            $this->order('#1002', 'buyer@example.com', '50.00', '2026-01-02T00:00:00Z'),
            $this->order('#1003', 'buyer@example.com', '50.00', '2026-01-03T00:00:00Z'),
        ]);

        $this->assertCount(1, $clusters);
        $this->assertCount(3, $clusters[0]['orders']);
    }

    public function test_it_ignores_lone_orders_blank_emails_and_non_positive_amounts(): void
    {
        $clusters = (new DuplicateOrderClusterer)->cluster([
            $this->order('#1001', 'solo@example.com', '50.00', '2026-01-10T10:00:00Z'),
            $this->order('#1002', '', '50.00', '2026-01-10T10:00:00Z'),
            $this->order('#1003', '', '50.00', '2026-01-10T11:00:00Z'),
            $this->order('#1004', 'free@example.com', '0.00', '2026-01-10T10:00:00Z'),
            $this->order('#1005', 'free@example.com', '0.00', '2026-01-10T11:00:00Z'),
            $this->order('#1006', 'rounds-to-zero@example.com', '0.40', '2026-01-10T10:00:00Z'),
            $this->order('#1007', 'rounds-to-zero@example.com', '0.40', '2026-01-10T11:00:00Z'),
        ]);

        $this->assertSame([], $clusters);
    }

    public function test_different_emails_or_amounts_are_never_clustered_together(): void
    {
        $clusters = (new DuplicateOrderClusterer)->cluster([
            $this->order('#1001', 'one@example.com', '50.00', '2026-01-10T10:00:00Z'),
            $this->order('#1002', 'two@example.com', '50.00', '2026-01-10T11:00:00Z'),
            $this->order('#1003', 'one@example.com', '80.00', '2026-01-10T12:00:00Z'),
        ]);

        $this->assertSame([], $clusters);
    }

    /** @return array<string, mixed> */
    private function order(string $name, string $email, string $total, string $createdAt): array
    {
        return ['name' => $name, 'email' => $email, 'total_price' => $total, 'created_at' => $createdAt];
    }
}
