<?php

declare(strict_types=1);

use App\Domain\Reports\DisputeAnalyzer;
use PHPUnit\Framework\TestCase;

final class DisputeParityTest extends TestCase
{
    /**
     * Sorts open chargebacks by response-deadline urgency -- getting this
     * wrong means an operator works on a dispute that's already lost its
     * evidence window while a genuinely urgent one waits.
     */
    public function test_rows_match_legacy(): void
    {
        $now = time();

        $disputes = [
            $this->dispute('d1', $now + 10 * 86400), // 10 days out
            $this->dispute('d2', null), // no deadline (e.g. UNDER_REVIEW) -> sorts last
            $this->dispute('d3', $now + 2 * 86400), // most urgent -> sorts first
            $this->dispute('d4', $now - 1 * 86400), // already past due -> still sorted by urgency (negative days)
        ];

        $legacyMethod = new ReflectionMethod(\DisputesPageLoader::class, 'buildDisputeRows');
        $legacyRows = $legacyMethod->invoke(null, $disputes, $now);

        $laravelRows = (new DisputeAnalyzer())->analyze($disputes, $now);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function dispute(string $id, ?int $dueByTs): array
    {
        return ['id' => $id, 'evidence_due_by' => $dueByTs === null ? '' : gmdate('Y-m-d\TH:i:s\Z', $dueByTs), 'status' => 'NEEDS_RESPONSE'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: mixed, days_until_due: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'days_until_due' => $r['days_until_due'],
        ], $rows);
    }
}
