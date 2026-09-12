<?php

namespace App\Domain\Reports;

use App\Domain\Orders\OrderChannelComparator;
use App\Domain\Orders\OrderTypeClassifier;

class ItemMismatchAnalyzer
{
    public function __construct(private readonly OrderChannelComparator $comparator, private readonly OrderTypeClassifier $classifier) {}

    /** @param list<array<string, mixed>> $shipStationOrders @param list<array<string, mixed>> $shopifyOrders @return list<array<string, mixed>> */
    public function analyze(array $shipStationOrders, array $shopifyOrders): array
    {
        $shopify = [];
        foreach ($shopifyOrders as $order) {
            $number = $this->number($order['name'] ?? $order['order_number'] ?? '');
            if ($number !== '') {
                $shopify[$number] = $order;
            }
        }
        $rows = [];
        foreach ($shipStationOrders as $order) {
            $number = $this->number($order['orderNumber'] ?? '');
            if (($order['orderStatus'] ?? '') !== 'shipped' || $number === '' || ! isset($shopify[$number])) {
                continue;
            }
            $shopifyOrder = $shopify[$number];
            if (($shopifyOrder['cancelled_at'] ?? null) || in_array($shopifyOrder['financial_status'] ?? '', ['refunded', 'voided'], true) || (array_key_exists('total_price', $shopifyOrder) && (float) $shopifyOrder['total_price'] === 0.0)) {
                continue;
            }
            $items = array_values(array_filter(is_array($order['items'] ?? null) ? $order['items'] : [], is_array(...)));
            $diff = $this->comparator->compare($shopifyOrder, ['items' => $items, 'status' => 'shipped'])['items'];
            if ($diff['missing'] === [] && $diff['extra'] === []) {
                continue;
            }
            $shippedOrder = ['line_items' => array_map(fn (array $item): array => ['sku' => $item['sku'] ?? '', 'title' => $item['name'] ?? $item['title'] ?? ''], $items)];
            $orderedMissing = $this->classifier->missingRequired($shopifyOrder);
            $missingRequired = [];
            foreach ($this->classifier->missingRequired($shippedOrder) as $type => $labels) {
                foreach (array_diff($labels, $orderedMissing[$type] ?? []) as $label) {
                    $missingRequired[] = $type.': '.$label;
                }
            }
            $rows[] = ['shopify_id' => $this->text($shopifyOrder['id'] ?? ''), 'order_number' => $this->text($shopifyOrder['name'] ?? ''), 'created_at' => substr($this->text($shopifyOrder['created_at'] ?? ''), 0, 10), 'email' => $this->text($shopifyOrder['email'] ?? ''), 'total' => is_numeric($shopifyOrder['total_price'] ?? null) ? (float) $shopifyOrder['total_price'] : 0.0, 'order_type' => $this->classifier->classify($shopifyOrder), 'ordered' => $diff['shopify'], 'shipped' => $diff['shipstation'], 'missing' => $diff['missing'], 'extra' => $diff['extra'], 'missing_required' => $missingRequired, 'ss_order_id' => $this->text($order['orderId'] ?? '')];
        }
        usort($rows, fn (array $a, array $b): int => (bool) $b['missing_required'] <=> (bool) $a['missing_required'] ?: count($b['missing']) + count($b['extra']) <=> count($a['missing']) + count($a['extra']));

        return $rows;
    }

    private function number(mixed $value): string
    {
        return mb_strtolower(ltrim($this->text($value), '#'));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
