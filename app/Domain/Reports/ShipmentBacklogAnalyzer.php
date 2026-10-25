<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use UnexpectedValueException;

class ShipmentBacklogAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array{awaiting: int, on_hold: int, age_buckets: array<string, int>}
     */
    public function analyze(array $orders, CarbonImmutable $capturedAt): array
    {
        $counts = ['awaiting' => 0, 'on_hold' => 0, 'age_buckets' => ['under_1_day' => 0, '1_2_days' => 0, '3_6_days' => 0, '7_plus_days' => 0, 'unknown' => 0]];
        $seen = [];
        foreach ($orders as $order) {
            $id = $order['orderId'] ?? null;
            $status = $order['orderStatus'] ?? null;
            if (! is_scalar($id) || ! ctype_digit((string) $id) || (int) $id < 1 || isset($seen[(string) $id]) || ! in_array($status, ['awaiting_shipment', 'on_hold'], true)) {
                throw new UnexpectedValueException('Backlog order identity or status could not be confirmed.');
            }
            $seen[(string) $id] = true;
            $counts[$status === 'on_hold' ? 'on_hold' : 'awaiting']++;
            $date = $order['orderDate'] ?? null;
            $age = null;
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $date)) {
                try {
                    $orderedAt = CarbonImmutable::parse($date, 'America/Los_Angeles');
                    if ($orderedAt->format('Y-m-d') === substr($date, 0, 10) && $orderedAt->lte($capturedAt)) {
                        $age = (int) floor(($capturedAt->getTimestamp() - $orderedAt->getTimestamp()) / 86400);
                    }
                } catch (\Throwable) {
                    $age = null;
                }
            }
            $bucket = match (true) {
                $age === null => 'unknown',
                $age < 1 => 'under_1_day',
                $age < 3 => '1_2_days',
                $age < 7 => '3_6_days',
                default => '7_plus_days',
            };
            $counts['age_buckets'][$bucket]++;
        }

        return $counts;
    }
}
