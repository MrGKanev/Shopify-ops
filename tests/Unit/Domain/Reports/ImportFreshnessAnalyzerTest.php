<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ImportFreshnessAnalyzer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ImportFreshnessAnalyzerTest extends TestCase
{
    private function issue(string $reference, string $firstSeen, int $occurrences = 1): array
    {
        return ['reference' => $reference, 'title' => 'Missing order '.$reference, 'priority' => 'normal', 'occurrences' => $occurrences, 'first_seen_at' => CarbonImmutable::parse($firstSeen), 'last_seen_at' => CarbonImmutable::parse('2026-10-28 11:00:00')];
    }

    public function test_orders_are_aged_from_the_first_absent_observation_against_the_grace_window(): void
    {
        $now = CarbonImmutable::parse('2026-10-28 12:00:00');

        $result = (new ImportFreshnessAnalyzer)->analyze([
            $this->issue('#1', '2026-10-28 08:00:00'),
            $this->issue('#2', '2026-10-27 12:00:00'),
            $this->issue('#3', '2026-10-28 06:00:00'),
        ], $now, 6);

        $this->assertSame(['#2', '#3', '#1'], array_column($result['rows'], 'reference'));
        $this->assertSame([24, 6, 4], array_column($result['rows'], 'age_hours'));
        $this->assertSame(['beyond_grace', 'beyond_grace', 'within_grace'], array_column($result['rows'], 'state'));
        $this->assertSame([1, 2, 24, true], [$result['within_grace'], $result['beyond_grace'], $result['oldest_age_hours'], $result['stale']]);
    }

    public function test_a_store_without_absent_orders_is_never_stale(): void
    {
        $result = (new ImportFreshnessAnalyzer)->analyze([], CarbonImmutable::parse('2026-10-28 12:00:00'), 1);

        $this->assertSame([], $result['rows']);
        $this->assertNull($result['oldest_age_hours']);
        $this->assertFalse($result['stale']);
    }

    public function test_the_age_is_never_negative_for_clock_skew(): void
    {
        $result = (new ImportFreshnessAnalyzer)->analyze([$this->issue('#1', '2026-10-28 13:00:00')], CarbonImmutable::parse('2026-10-28 12:00:00'), 6);

        $this->assertSame(0, $result['rows'][0]['age_hours']);
        $this->assertFalse($result['stale']);
    }
}
