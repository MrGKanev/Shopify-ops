<?php

namespace App\Domain\Reports;

class ShippedUnfulfilledAnalyzer
{
    /** @param list<array<string,mixed>> $ssOrders @param list<array<string,mixed>> $shopifyOrders @return array{shipped_total:int,rows:list<array<string,mixed>>} */
    public function analyze(array $ssOrders, array $shopifyOrders): array
    {
        $index = [];
        foreach ($shopifyOrders as $order) {
            $number = $this->number($order['name'] ?? $order['order_number'] ?? '');
            if ($number !== '') {
                $index[$number] = $order;
            }
        }$rows = [];
        $shipped = 0;
        foreach ($ssOrders as $order) {
            if (($order['orderStatus'] ?? '') !== 'shipped') {
                continue;
            }$shipped++;
            $number = $this->number($order['orderNumber'] ?? '');
            if ($number === '' || ! isset($index[$number])) {
                continue;
            }$shopify = $index[$number];
            $status = $this->text($shopify['fulfillment_status'] ?? '');
            if ($status === 'fulfilled') {
                continue;
            }$shipTo = is_array($order['shipTo'] ?? null) ? $order['shipTo'] : [];
            $rows[] = ['ss_order_id' => $this->text($order['orderId'] ?? ''), 'order_number' => $this->text($order['orderNumber'] ?? ''), 'order_date' => substr($this->text($order['orderDate'] ?? ''), 0, 10), 'customer' => $this->text($shipTo['name'] ?? ''), 'email' => $this->text($order['customerEmail'] ?? ''), 'total' => is_numeric($order['orderTotal'] ?? null) ? (float) $order['orderTotal'] : 0.0, 'sh_fulfillment' => $status ?: 'unfulfilled', 'sh_financial' => $this->text($shopify['financial_status'] ?? ''), 'shopify_id' => $this->text($shopify['id'] ?? '')];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['order_date'], $a['order_date']));

        return ['shipped_total' => $shipped, 'rows' => $rows];
    }

    private function number(mixed $value): string
    {
        return preg_replace('/\D+/', '', $this->text($value)) ?? '';
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
