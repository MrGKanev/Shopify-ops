<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\DuplicateOrderAnalyzer;
use PHPUnit\Framework\TestCase;

class DuplicateOrderAnalyzerTest extends TestCase
{
    public function test_it_matches_normalized_email_amount_and_inclusive_ten_minute_window(): void
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

        $this->assertSame([['#1', '#2', 600], ['#2', '#3', 1]], array_map(fn (array $pair): array => [$pair['first']['name'], $pair['second']['name'], $pair['gap_seconds']], $pairs));
    }

    /** @return array<string, mixed> */
    private function order(string $name, string $email, string $amount, string $createdAt): array
    {
        return ['name' => $name, 'email' => $email, 'total_price' => $amount, 'created_at' => $createdAt];
    }
}
