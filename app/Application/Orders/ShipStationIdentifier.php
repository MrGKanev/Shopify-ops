<?php

namespace App\Application\Orders;

/**
 * Reads ShipStation numeric identifiers the same way everywhere: a positive integer or its plain digit string, nothing else.
 * Booleans, floats, arrays and signed or padded-with-whitespace strings are not identifiers.
 */
final class ShipStationIdentifier
{
    public static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/\A[0-9]{1,18}\z/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
