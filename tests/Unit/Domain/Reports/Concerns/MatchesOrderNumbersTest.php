<?php

namespace Tests\Unit\Domain\Reports\Concerns;

use App\Domain\Reports\Concerns\MatchesOrderNumbers;
use PHPUnit\Framework\TestCase;

class MatchesOrderNumbersTest extends TestCase
{
    public function test_order_number_keeps_digits_only(): void
    {
        $this->assertSame('1001', $this->matcher()->number('#1001'));
        $this->assertSame('1001', $this->matcher()->number(' SS-1001 '));
        $this->assertSame('1001', $this->matcher()->number(1001));
        $this->assertSame('', $this->matcher()->number('no-digits'));
        $this->assertSame('', $this->matcher()->number(null));
        $this->assertSame('', $this->matcher()->number(['#1001']));
    }

    public function test_keys_lead_with_all_digits_joined_then_add_fragments(): void
    {
        $this->assertSame(['20261001', '2026', '1001'], $this->matcher()->keys('2026-1001'));
    }

    public function test_fragments_shorter_than_four_digits_are_dropped(): void
    {
        $this->assertSame(['121001', '1001'], $this->matcher()->keys('12-1001'));
    }

    public function test_a_single_digit_run_yields_one_key(): void
    {
        $this->assertSame(['1001'], $this->matcher()->keys('#1001'));
    }

    public function test_keys_are_deduplicated_and_empty_for_digitless_values(): void
    {
        $this->assertSame(['10011001', '1001'], $this->matcher()->keys('1001-1001'));
        $this->assertSame([], $this->matcher()->keys('no-digits'));
    }

    private function matcher(): object
    {
        return new class
        {
            use MatchesOrderNumbers;

            public function number(mixed $value): string
            {
                return $this->orderNumber($value);
            }

            /** @return list<string> */
            public function keys(mixed $value): array
            {
                return $this->orderNumberKeys($value);
            }
        };
    }
}
