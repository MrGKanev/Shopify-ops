<?php

namespace App\Domain\Reports;

class FulfilledItemsAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  list<array{alias: string, master: string}>  $aliases
     * @return list<array<string, mixed>>
     */
    public function analyze(array $orders, string $startDate, string $endDate, array $aliases = []): array
    {
        $start = $startDate.'T00:00:00Z';
        $end = $endDate.'T23:59:59Z';
        $totals = [];
        $skuMap = new SkuAliasMap($aliases);

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
                    $sku = trim(is_scalar($item['sku'] ?? null) ? (string) $item['sku'] : '');
                    if ($product !== '' || $sku !== '') {
                        $skuMap->add($totals, $product, $sku, is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0);
                    }
                }
            }
        }

        return $skuMap->rows($totals);
    }
}
