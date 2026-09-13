<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DualAutoloadSmokeTest extends TestCase
{
    public function test_legacy_and_laravel_classes_load_in_the_same_process(): void
    {
        $this->assertSame([], \Comparator::findDuplicates([]));
        $this->assertSame([], (new \App\Domain\Reports\DuplicateOrderAnalyzer())->analyze([]));
    }
}
