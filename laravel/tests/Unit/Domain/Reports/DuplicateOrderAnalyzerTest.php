<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\DuplicateOrderAnalyzer;
use PHPUnit\Framework\TestCase;

class DuplicateOrderAnalyzerTest extends TestCase
{
    public function test_it_matches_normalized_email_and_amount_within_a_day(): void
    {
        $orders = [
            $this->order('#1', ' Jane@Example.com ', '50.00', '2026-09-01T10:00:00Z'),
            $this->order('#2', 'jane@example.com', '50.00', '2026-09-01T10:10:00Z'),
            $this->order('#3', 'jane@example.com', '50.00', '2026-09-01T10:10:01Z'),
            $this->order('#4', 'jane@example.com', '75.00', '2026-09-01T10:05:00Z'),
            $this->order('#5', '', '50.00', '2026-09-01T10:05:00Z'),
            $this->order('#6', 'jane@example.com', '50.00', 'invalid'),
        ];

        $pairs = (new DuplicateOrderAnalyzer)->analyze($orders);

        $this->assertSame([['#1', '#2', 600], ['#1', '#3', 601], ['#2', '#3', 1]], array_map(fn (array $pair): array => [$pair['first']['name'], $pair['second']['name'], $pair['gap_seconds']], $pairs));
    }

    public function test_window_is_inclusive_of_exactly_24_hours_apart(): void
    {
        $orders = [
            $this->order('#1', 'jane@example.com', '50.00', '2026-09-01T10:00:00Z'),
            $this->order('#2', 'jane@example.com', '50.00', '2026-09-02T10:00:00Z'),
        ];

        $pairs = (new DuplicateOrderAnalyzer)->analyze($orders);

        $this->assertCount(1, $pairs);
        $this->assertSame(86400, $pairs[0]['gap_seconds']);
    }

    public function test_window_excludes_orders_one_second_past_24_hours(): void
    {
        $orders = [
            $this->order('#1', 'jane@example.com', '50.00', '2026-09-01T10:00:00Z'),
            $this->order('#2', 'jane@example.com', '50.00', '2026-09-02T10:00:01Z'),
        ];

        $this->assertSame([], (new DuplicateOrderAnalyzer)->analyze($orders));
    }

    /** @return array<string, mixed> */
    private function order(string $name, string $email, string $amount, string $createdAt): array
    {
        return ['name' => $name, 'email' => $email, 'total_price' => $amount, 'created_at' => $createdAt];
    }
}
