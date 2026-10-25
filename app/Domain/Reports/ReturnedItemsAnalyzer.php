<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Concerns\NormalizesText;

class ReturnedItemsAnalyzer
{
    use NormalizesText;

    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  list<array{alias: string, master: string}>  $aliases
     * @return list<array<string, mixed>>
     */
    public function analyze(array $orders, string $startDate, string $endDate, array $aliases = []): array
    {
        $rangeStart = $startDate.'T00:00:00Z';
        $rangeEnd = $endDate.'T23:59:59Z';
        $totals = [];
        $skuMap = new SkuAliasMap($aliases);

        foreach ($orders as $order) {
            foreach (is_array($order['refunds'] ?? null) ? $order['refunds'] : [] as $refund) {
                if (! is_array($refund)) {
                    continue;
                }

                $createdAt = $this->text($refund['created_at'] ?? '');
                if ($createdAt < $rangeStart || $createdAt > $rangeEnd) {
                    continue;
                }

                foreach (is_array($refund['refund_line_items'] ?? null) ? $refund['refund_line_items'] : [] as $refundLineItem) {
                    if (! is_array($refundLineItem)) {
                        continue;
                    }

                    $lineItem = is_array($refundLineItem['line_item'] ?? null) ? $refundLineItem['line_item'] : [];
                    $product = $this->text($lineItem['name'] ?? $lineItem['title'] ?? '');
                    $sku = $this->text($lineItem['sku'] ?? '');
                    if ($product === '' && $sku === '') {
                        continue;
                    }

                    $quantity = is_numeric($refundLineItem['quantity'] ?? null) ? (int) $refundLineItem['quantity'] : 0;
                    $skuMap->add($totals, $product, $sku, $quantity);
                }
            }
        }

        return $skuMap->rows($totals);
    }
}
