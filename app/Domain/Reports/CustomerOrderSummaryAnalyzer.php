<?php

namespace App\Domain\Reports;

class CustomerOrderSummaryAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return array{total_spent: float, currency: string, paid: int, cancelled: int, tags: array<string, int>} */
    public function analyze(array $orders): array
    {
        $total = 0.0;
        $currency = 'USD';
        $paid = $cancelled = 0;
        $tags = [];
        foreach ($orders as $order) {
            $total += is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0;
            $currency = is_scalar($order['currency'] ?? null) && trim((string) $order['currency']) !== '' ? (string) $order['currency'] : $currency;
            $paid += ($order['financial_status'] ?? '') === 'paid' ? 1 : 0;
            $cancelled += ($order['cancelled_at'] ?? null) ? 1 : 0;
            foreach (is_array($order['tags'] ?? null) ? $order['tags'] : [] as $tag) {
                if (is_scalar($tag) && trim((string) $tag) !== '') {
                    $tag = trim((string) $tag);
                    $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                }
            }
        }
        arsort($tags);

        return ['total_spent' => $total, 'currency' => $currency, 'paid' => $paid, 'cancelled' => $cancelled, 'tags' => $tags];
    }
}
