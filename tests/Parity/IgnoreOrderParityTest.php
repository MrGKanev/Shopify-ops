<?php

declare(strict_types=1);

use App\Http\Controllers\IgnoredOrderController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IgnoreOrderParityTest extends TestCase
{
    /**
     * Both sides reduce a raw order-number input (from the single ignore/unignore
     * form, or a bulk selection) down to a digits-only key before storing/looking
     * up an ignore entry: legacy `Comparator::normalise()` (`src/Comparator.php`),
     * Laravel `IgnoredOrderController::normalize()` (private, reflected here since
     * both `store()` and `import()` route through it and it has no public seam).
     */
    #[DataProvider('rawOrderNumberProvider')]
    public function test_order_number_normalisation_matches_legacy(string $raw): void
    {
        $this->assertSame(\Comparator::normalise($raw), $this->laravelNormalize($raw));
    }

    /** @return list<array{0: string}> */
    public static function rawOrderNumberProvider(): array
    {
        return [
            ['#12345'],
            [' 123 '],
            ['12-345'],
            ['abc'],
            ['abc123def456'],
            ['  '],
            ['#'],
            ['0012345'],
            ['#100042-B2'],
        ];
    }

    private function laravelNormalize(string $raw): string
    {
        $method = new ReflectionMethod(IgnoredOrderController::class, 'normalize');

        return $method->invoke(new IgnoredOrderController(), $raw);
    }
}
