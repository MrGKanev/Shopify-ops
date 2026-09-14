<?php

declare(strict_types=1);

use App\Http\Controllers\IgnoredOrderController;
use PHPUnit\Framework\TestCase;

final class BulkIgnoreParityTest extends TestCase
{
    /**
     * Legacy `Actions::buildBulkIgnoreEntries()` (`src/Actions.php`) normalizes
     * each raw order number and drops any that normalize to an empty string,
     * keeping duplicates and preserving input order. Laravel's
     * `IgnoredOrderController::buildBulkEntries()` (private, reflected here
     * since `bulkStore()` has no other callable seam) must match exactly.
     */
    public function test_bulk_entries_match_legacy(): void
    {
        $raw = ['#1001', '', '  ', '1001', 'abc', '#100042-B2', '0012345'];

        $legacyEntries = \Actions::buildBulkIgnoreEntries($raw, 'Repeat offender');

        $laravelMethod = new ReflectionMethod(IgnoredOrderController::class, 'buildBulkEntries');
        $laravelEntries = $laravelMethod->invoke(new IgnoredOrderController(), $raw, 'Repeat offender');

        $this->assertSame($legacyEntries, $laravelEntries);
    }
}
