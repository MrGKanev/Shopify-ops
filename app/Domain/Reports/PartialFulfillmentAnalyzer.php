<?php

namespace App\Domain\Reports;

class PartialFulfillmentAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders, int $threshold, int $now): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $lastFulfilled = '';
            foreach (is_array($order['fulfillments'] ?? null) ? $order['fulfillments'] : [] as $fulfillment) {
                $date = is_array($fulfillment) ? $this->text($fulfillment['created_at'] ?? '') : '';
                if ($date > $lastFulfilled) {
                    $lastFulfilled = $date;
                }
            }
            $stallSince = $lastFulfilled ?: $this->text($order['created_at'] ?? '');
            $stallTimestamp = strtotime($stallSince);
            $daysStalled = $stallTimestamp === false ? 0 : (int) floor(($now - $stallTimestamp) / 86400);
            if ($daysStalled < $threshold) {
                continue;
            }
            $items = [];
            foreach (is_array($order['line_items'] ?? null) ? $order['line_items'] : [] as $item) {
                $quantity = is_array($item) ? (int) ($item['fulfillable_quantity'] ?? 0) : 0;
                if ($quantity > 0) {
                    $items[] = ['name' => $this->text($item['name'] ?? $item['title'] ?? ''), 'sku' => $this->text($item['sku'] ?? ''), 'qty' => $quantity];
                }
            }
            if ($items === []) {
                continue;
            }
            $rows[] = [
                'shopify_id' => $this->text($order['id'] ?? ''), 'order_number' => $this->text($order['name'] ?? ''),
                'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'last_fulfilled' => substr($lastFulfilled, 0, 10),
                'days_stalled' => $daysStalled, 'email' => $this->text($order['email'] ?? ''),
                'total_price' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0,
                'financial' => $this->text($order['financial_status'] ?? ''), 'unfulfilled_items' => $items,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['days_stalled'] <=> $a['days_stalled']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
