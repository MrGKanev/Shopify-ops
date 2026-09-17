<?php

namespace Tests\Unit\Application\Reports;

use App\Application\Reports\ScanResult;
use PHPUnit\Framework\TestCase;

class ScanResultTest extends TestCase
{
    public function test_it_round_trips_the_full_shape(): void
    {
        $rows = [['sku' => 'ABC123']];

        $result = new ScanResult(
            scanned: 5,
            rows: $rows,
            pages: 2,
            truncated: true,
            startDate: '2026-01-01',
            endDate: '2026-01-31',
            threshold: 24,
            minimum: 3,
            minimumEmails: 4,
            skippedMissingCountry: 1,
            days: 30,
            totalVariants: 10,
        );

        $this->assertSame(5, $result->scanned);
        $this->assertSame($rows, $result->rows);
        $this->assertSame(2, $result->pages);
        $this->assertTrue($result->truncated);
        $this->assertSame('2026-01-01', $result->startDate);
        $this->assertSame('2026-01-31', $result->endDate);
        $this->assertSame(24, $result->threshold);
        $this->assertSame(3, $result->minimum);
        $this->assertSame(4, $result->minimumEmails);
        $this->assertSame(1, $result->skippedMissingCountry);
        $this->assertSame(30, $result->days);
        $this->assertSame(10, $result->totalVariants);
    }

    public function test_optional_fields_default_to_null(): void
    {
        $result = new ScanResult(scanned: 0, rows: []);

        $this->assertNull($result->pages);
        $this->assertNull($result->truncated);
        $this->assertNull($result->startDate);
        $this->assertNull($result->endDate);
        $this->assertNull($result->threshold);
        $this->assertNull($result->minimum);
        $this->assertNull($result->minimumEmails);
        $this->assertNull($result->skippedMissingCountry);
        $this->assertNull($result->days);
        $this->assertNull($result->totalVariants);
    }
}
