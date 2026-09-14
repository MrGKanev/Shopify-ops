<?php

declare(strict_types=1);

use App\Http\Controllers\GlobalSearchController;
use PHPUnit\Framework\TestCase;

final class GlobalSearchParityTest extends TestCase
{
    public function test_order_number_matching_matches_legacy(): void
    {
        $method = new ReflectionMethod(GlobalSearchController::class, 'matches');
        $controller = new GlobalSearchController;

        foreach ([
            ['#1001-A', '1001'],
            ['100', '1001'],
            ['Order 2002', '2002'],
            ['9999', '1001'],
            ['', '1001'],
        ] as [$candidate, $query]) {
            $legacyCandidate = Comparator::normalise($candidate);
            $legacyQuery = Comparator::normalise($query);
            $legacy = $legacyCandidate !== '' && $legacyQuery !== '' && (str_contains($legacyCandidate, $legacyQuery) || str_contains($legacyQuery, $legacyCandidate));

            $this->assertSame($legacy, $method->invoke($controller, $candidate, $legacyQuery));
        }
    }
}
