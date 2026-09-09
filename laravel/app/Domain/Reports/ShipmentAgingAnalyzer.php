<?php

namespace App\Domain\Reports;

use App\Domain\Orders\OrderTypeClassifier;

class ShipmentAgingAnalyzer
{
    public function __construct(private readonly OrderTypeClassifier $classifier) {}

    /** @param list<array<string, mixed>> $orders @return array{rows: list<array<string, mixed>>, by_sku: list<array<string, mixed>>, by_type: list<array<string, mixed>>} */
    public function analyze(array $orders, int $threshold, int $now): array
    {
        $rows = $bySku = $byType = [];
        foreach ($orders as $order) {
            $date = $this->text($order['orderDate'] ?? $order['createDate'] ?? '');
            $timestamp = strtotime($date);
            if ($timestamp === false || ($days = (int) floor(($now - $timestamp) / 86400)) < $threshold) {
                continue;
            }
            $items = array_values(array_filter(is_array($order['items'] ?? null) ? $order['items'] : [], is_array(...)));
            $type = $this->classifier->classify(['line_items' => array_map(fn (array $item): array => ['sku' => $this->text($item['sku'] ?? ''), 'title' => $this->text($item['name'] ?? '')], $items)]);
            $skus = [];
            foreach ($items as $item) {
                $sku = $this->text($item['sku'] ?? '');
                if ($sku === '') {
                    continue;
                }
                $quantity = (int) ($item['quantity'] ?? 1);
                $skus[$sku] = ($skus[$sku] ?? 0) + $quantity;
                $bySku[$sku] ??= ['sku' => $sku, 'orders' => 0, 'qty' => 0, 'oldest_days' => 0];
                $bySku[$sku]['qty'] += $quantity;
                $bySku[$sku]['oldest_days'] = max($bySku[$sku]['oldest_days'], $days);
            }
            foreach (array_keys($skus) as $sku) {
                $bySku[$sku]['orders']++;
            }
            $byType[$type] ??= ['type' => $type, 'orders' => 0, 'oldest_days' => 0];
            $byType[$type]['orders']++;
            $byType[$type]['oldest_days'] = max($byType[$type]['oldest_days'], $days);
            $shipTo = is_array($order['shipTo'] ?? null) ? $order['shipTo'] : [];
            $rows[] = ['ss_order_id' => $this->text($order['orderId'] ?? ''), 'order_number' => $this->text($order['orderNumber'] ?? ''), 'order_date' => substr($date, 0, 10), 'days' => $days, 'customer' => $this->text($shipTo['name'] ?? ''), 'email' => $this->text($order['customerEmail'] ?? ''), 'total' => is_numeric($order['orderTotal'] ?? null) ? (float) $order['orderTotal'] : 0.0, 'status' => $this->text($order['orderStatus'] ?? ''), 'order_type' => $type, 'skus' => $skus];
        }
        usort($rows, fn (array $a, array $b): int => $b['days'] <=> $a['days']);
        $sort = fn (array $a, array $b): int => $b['oldest_days'] <=> $a['oldest_days'] ?: $b['orders'] <=> $a['orders'];
        usort($bySku, $sort);
        usort($byType, $sort);

        return ['rows' => $rows, 'by_sku' => $bySku, 'by_type' => $byType];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
