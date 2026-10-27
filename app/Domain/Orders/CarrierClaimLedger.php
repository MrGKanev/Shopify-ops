<?php

namespace App\Domain\Orders;

/**
 * Exact claim arithmetic in micro-units. Goods and postage are tracked separately; a claim approval never counts as money received.
 */
class CarrierClaimLedger
{
    public static function toMicros(string $amount): int
    {
        if (preg_match('/\A(\d{1,12})(?:\.(\d{1,6}))?\z/', trim($amount), $parts) !== 1) {
            throw new \InvalidArgumentException('Invalid amount.');
        }

        return (int) $parts[1] * 1_000_000 + (int) str_pad($parts[2] ?? '', 6, '0');
    }

    public static function format(int $micros): string
    {
        return ($micros < 0 ? '-' : '').intdiv(abs($micros), 1_000_000).'.'.str_pad((string) (abs($micros) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, string|null>  $claim  goods_requested, goods_approved, postage_requested, postage_approved
     * @param  list<array{kind: string, amount: string}>  $payments
     * @return array<string, array{requested: string|null, approved: string|null, received: string, outstanding: string|null, over_received: bool}>
     */
    public function summarize(array $claim, array $payments): array
    {
        $summary = [];
        foreach (['goods', 'postage'] as $kind) {
            $received = array_sum(array_map(fn (array $payment): int => $payment['kind'] === $kind ? self::toMicros($payment['amount']) : 0, $payments));
            $approved = isset($claim[$kind.'_approved']) ? self::toMicros($claim[$kind.'_approved']) : null;
            $summary[$kind] = [
                'requested' => isset($claim[$kind.'_requested']) ? self::format(self::toMicros($claim[$kind.'_requested'])) : null,
                'approved' => $approved === null ? null : self::format($approved),
                'received' => self::format($received),
                'outstanding' => $approved === null ? null : self::format(max(0, $approved - $received)),
                'over_received' => $approved !== null && $received > $approved,
            ];
        }

        return $summary;
    }
}
