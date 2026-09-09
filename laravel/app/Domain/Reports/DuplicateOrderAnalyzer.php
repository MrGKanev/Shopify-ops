<?php

namespace App\Domain\Reports;

use DateTimeImmutable;
use Throwable;

class DuplicateOrderAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array{first: array<string, mixed>, second: array<string, mixed>, gap_seconds: int}> */
    public function analyze(array $orders): array
    {
        $groups = [];
        foreach ($orders as $order) {
            $email = mb_strtolower(trim(is_scalar($order['email'] ?? null) ? (string) $order['email'] : ''));
            $amount = is_scalar($order['total_price'] ?? null) ? (string) $order['total_price'] : '0';
            if ($email !== '') {
                $groups[$email.'|'.$amount][] = $order;
            }
        }

        $pairs = [];
        foreach ($groups as $group) {
            for ($first = 0, $count = count($group); $first < $count; $first++) {
                for ($second = $first + 1; $second < $count; $second++) {
                    $gap = $this->gap($group[$first]['created_at'] ?? null, $group[$second]['created_at'] ?? null);
                    if ($gap !== null && $gap <= 600) {
                        $pairs[] = ['first' => $group[$first], 'second' => $group[$second], 'gap_seconds' => $gap];
                    }
                }
            }
        }

        return $pairs;
    }

    private function gap(mixed $first, mixed $second): ?int
    {
        if (! is_string($first) || ! is_string($second)) {
            return null;
        }

        try {
            return abs((new DateTimeImmutable($first))->getTimestamp() - (new DateTimeImmutable($second))->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }
}
