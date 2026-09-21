<?php

namespace App\Domain\Reports\Concerns;

trait MatchesOrderNumbers
{
    private const int MIN_ORDER_NUMBER_FRAGMENT_LENGTH = 4;

    private function orderNumber(mixed $value): string
    {
        return preg_replace('/\D+/', '', is_scalar($value) ? (string) $value : '') ?? '';
    }

    /** @return list<string> */
    private function orderNumberKeys(mixed $value): array
    {
        $raw = is_scalar($value) ? (string) $value : '';
        $full = $this->orderNumber($raw);

        if ($full === '') {
            return [];
        }

        $keys = [$full];
        preg_match_all('/\d+/', $raw, $matches);

        foreach ($matches[0] as $fragment) {
            if ($fragment !== $full && strlen($fragment) >= self::MIN_ORDER_NUMBER_FRAGMENT_LENGTH) {
                $keys[] = $fragment;
            }
        }

        return array_values(array_unique($keys));
    }
}
