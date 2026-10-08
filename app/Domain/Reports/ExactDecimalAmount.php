<?php

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;

class ExactDecimalAmount
{
    public function parse(mixed $value): ?BigDecimal
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^-?[0-9]{1,18}(?:\.[0-9]{1,12})?$/D', (string) $value) !== 1) {
            return null;
        }

        return BigDecimal::of((string) $value);
    }

    public function money(mixed $money, string $currency): ?BigDecimal
    {
        return is_array($money) && ($money['currencyCode'] ?? null) === $currency ? $this->parse($money['amount'] ?? null) : null;
    }
}
