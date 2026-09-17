<?php

namespace App\Domain\Reports\Concerns;

trait NormalizesText
{
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
