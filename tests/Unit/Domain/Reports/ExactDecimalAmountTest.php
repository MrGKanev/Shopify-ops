<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ExactDecimalAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExactDecimalAmountTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_accepts_bounded_decimal_strings_and_integers_without_losing_precision(string|int $input, string $expected): void
    {
        $amount = (new ExactDecimalAmount)->parse($input);

        $this->assertSame($expected, (string) $amount);
    }

    public static function validAmounts(): array
    {
        return [
            'integer zero' => [0, '0'],
            'negative integer' => [-123, '-123'],
            'leading zeros' => ['00012.3400', '12.3400'],
            'negative zero' => ['-0.000000', '0.000000'],
            'smallest supported fractional unit' => ['0.000000000001', '0.000000000001'],
            'maximum integer precision' => ['999999999999999999', '999999999999999999'],
            'signed maximum precision' => ['-999999999999999999.999999999999', '-999999999999999999.999999999999'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_ambiguous_unbounded_or_nondecimal_amounts(mixed $input): void
    {
        $amount = (new ExactDecimalAmount)->parse($input);

        $this->assertNull($amount);
    }

    public static function invalidAmounts(): array
    {
        return [
            'missing amount' => [null],
            'boolean' => [true],
            'array' => [['amount' => '1.00']],
            'float even when integral' => [1.0],
            'not a number' => [NAN],
            'infinity' => [INF],
            'blank string' => [''],
            'leading whitespace' => [' 1.00'],
            'trailing whitespace' => ['1.00 '],
            'trailing line break' => ["1.00\n"],
            'positive sign' => ['+1.00'],
            'missing integer' => ['.50'],
            'missing fraction' => ['1.'],
            'locale comma' => ['1,50'],
            'thousands separator' => ['1,000.00'],
            'integer precision overflow' => ['1000000000000000000'],
            'fraction precision overflow' => ['0.0000000000001'],
            'hexadecimal' => ['0x10'],
            'scientific notation' => ['2E3'],
        ];
    }

    #[DataProvider('invalidMoney')]
    public function test_money_requires_a_matching_currency_and_valid_amount(mixed $money): void
    {
        $amount = (new ExactDecimalAmount)->money($money, 'EUR');

        $this->assertNull($amount);
    }

    public static function invalidMoney(): array
    {
        return [
            'missing money' => [null],
            'scalar money' => ['12.50'],
            'missing currency' => [['amount' => '12.50']],
            'wrong currency' => [['amount' => '12.50', 'currencyCode' => 'USD']],
            'currency is case sensitive' => [['amount' => '12.50', 'currencyCode' => 'eur']],
            'missing amount' => [['currencyCode' => 'EUR']],
            'rounded float amount' => [['amount' => 12.5, 'currencyCode' => 'EUR']],
        ];
    }

    public function test_money_preserves_signed_fractional_value_for_the_requested_currency(): void
    {
        $amount = (new ExactDecimalAmount)->money(['amount' => '-0.000000000009', 'currencyCode' => 'EUR'], 'EUR');

        $this->assertSame('-0.000000000009', (string) $amount);
    }
}
