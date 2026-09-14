<?php

namespace App\Domain\Reports;

class FulfilledItemsAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array{product: string, quantity: int}> */
    public function analyze(array $orders, string $startDate, string $endDate): array
    {
        $start = $startDate.'T00:00:00Z';
        $end = $endDate.'T23:59:59Z';
        $totals = [];

        foreach ($orders as $order) {
            foreach (is_array($order['fulfillments'] ?? null) ? $order['fulfillments'] : [] as $fulfillment) {
                if (! is_array($fulfillment) || ($fulfillment['status'] ?? '') !== 'success' || ($fulfillment['created_at'] ?? '') < $start || ($fulfillment['created_at'] ?? '') > $end) {
                    continue;
                }
                foreach (is_array($fulfillment['line_items'] ?? null) ? $fulfillment['line_items'] : [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $title = trim(is_scalar($item['title'] ?? null) ? (string) $item['title'] : '');
                    $variant = trim(is_scalar($item['variant_title'] ?? null) ? (string) $item['variant_title'] : '');
                    $product = trim($title.($variant !== '' && $variant !== 'Default Title' ? ' '.$variant : ''));
                    if ($product !== '') {
                        $totals[$product] = ($totals[$product] ?? 0) + (is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0);
                    }
                }
            }
        }
        ksort($totals);

        return array_map(fn (string $product, int $quantity): array => compact('product', 'quantity'), array_keys($totals), array_values($totals));
    }
}
