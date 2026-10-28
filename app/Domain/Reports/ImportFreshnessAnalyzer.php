<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

class ImportFreshnessAnalyzer
{
    /**
     * Ages expected-but-absent orders against the store's grace window.
     *
     * Age runs from the first time an audit saw the order absent. These are observation intervals between audits, not upstream import durations, and a quiet store with no absent orders is never reported as stale.
     *
     * @param  list<array{reference: string, title: string, priority: string, occurrences: int, first_seen_at: CarbonImmutable, last_seen_at: CarbonImmutable}>  $issues
     * @return array{rows: list<array{reference: string, title: string, priority: string, occurrences: int, first_seen_at: CarbonImmutable, last_seen_at: CarbonImmutable, age_hours: int, state: string}>, within_grace: int, beyond_grace: int, oldest_age_hours: int|null, stale: bool}
     */
    public function analyze(array $issues, CarbonImmutable $now, int $graceHours): array
    {
        $rows = [];
        foreach ($issues as $issue) {
            $age = max(0, (int) floor($issue['first_seen_at']->diffInMinutes($now) / 60));
            $rows[] = $issue + ['age_hours' => $age, 'state' => $age >= $graceHours ? 'beyond_grace' : 'within_grace'];
        }
        usort($rows, fn (array $left, array $right): int => [$right['age_hours'], $left['reference']] <=> [$left['age_hours'], $right['reference']]);
        $beyond = count(array_filter($rows, fn (array $row): bool => $row['state'] === 'beyond_grace'));

        return ['rows' => $rows, 'within_grace' => count($rows) - $beyond, 'beyond_grace' => $beyond, 'oldest_age_hours' => $rows === [] ? null : $rows[0]['age_hours'], 'stale' => $beyond > 0];
    }
}
