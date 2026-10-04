<?php

namespace Tests\Unit\Application\Reports;

use App\Application\Reports\ReportResult;
use App\Application\Reports\RunScanReport;
use PHPUnit\Framework\TestCase;

class ReportResultTest extends TestCase
{
    public function test_it_round_trips_the_full_shape(): void
    {
        $rows = [['sku' => 'ABC123']];

        $result = new ReportResult(rows: $rows, scanned: 5, pages: 2, truncated: true, params: ['startDate' => '2026-01-01', 'endDate' => '2026-01-31', 'threshold' => 24, 'minimum' => 3, 'minimumEmails' => 4, 'days' => 30], meta: ['skippedMissingCountry' => 1, 'totalVariants' => 10]);

        $this->assertSame($result->toArray(), ReportResult::fromArray(json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toArray());
        $this->assertSame(5, $result->scanned);
        $this->assertSame($rows, $result->rows);
        $this->assertSame(2, $result->pages);
        $this->assertTrue($result->truncated);
        $this->assertSame('2026-01-01', $result->params['startDate']);
        $this->assertSame('2026-01-31', $result->params['endDate']);
        $this->assertSame(24, $result->params['threshold']);
        $this->assertSame(3, $result->params['minimum']);
        $this->assertSame(4, $result->params['minimumEmails']);
        $this->assertSame(1, $result->meta['skippedMissingCountry']);
        $this->assertSame(30, $result->params['days']);
        $this->assertSame(10, $result->meta['totalVariants']);
    }

    public function test_defaults_have_no_report_specific_fields(): void
    {
        $result = new ReportResult(rows: [], scanned: 0);

        $this->assertSame(0, $result->pages);
        $this->assertFalse($result->truncated);
        $this->assertSame([], $result->params);
        $this->assertSame([], $result->meta);
    }

    public function test_scan_reports_preserve_candidate_pagination_and_metadata(): void
    {
        $report = new class extends RunScanReport
        {
            /** @param array<string, mixed> $candidates @param list<array<string, mixed>> $rows */
            public function result(array $candidates, array $rows): ReportResult
            {
                return $this->scanResult($candidates, 'orders', $rows, ['threshold' => 24]);
            }
        };

        $result = $report->result(['orders' => [['id' => 1], ['id' => 2]], 'pages' => 3, 'truncated' => true], [['id' => 2]]);

        $this->assertSame(2, $result->scanned);
        $this->assertSame([['id' => 2]], $result->rows);
        $this->assertSame(3, $result->pages);
        $this->assertTrue($result->truncated);
        $this->assertSame(24, $result->params['threshold']);
    }
}
