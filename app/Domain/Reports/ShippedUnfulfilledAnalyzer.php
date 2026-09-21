<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Concerns\NormalizesText;
use App\Domain\Reports\Concerns\MatchesOrderNumbers;

class ShippedUnfulfilledAnalyzer
{
    use MatchesOrderNumbers, NormalizesText;

    /** @param list<array<string,mixed>> $ssOrders @param list<array<string,mixed>> $shopifyOrders @return array{shipped_total:int,rows:list<array<string,mixed>>} */
    public function analyze(array $ssOrders, array $shopifyOrders): array
    {
        $index = [];
        foreach ($shopifyOrders as $order) {
            $number = $this->orderNumber($order['order_number'] ?? $order['name'] ?? '');
            if ($number !== '') {
                $index[$number] = $order;
            }
        }
        $rows = [];
        $shipped = 0;
        foreach ($ssOrders as $order) {
            if (($order['orderStatus'] ?? '') !== 'shipped') {
                continue;
            }
            $shipped++;
            $number = array_find($this->orderNumberKeys($order['orderNumber'] ?? ''), fn (string $key): bool => isset($index[$key]));
            if ($number === null) {
                continue;
            }
            $shopify = $index[$number];
            $status = $this->text($shopify['fulfillment_status'] ?? '');
            if ($status === 'fulfilled') {
                continue;
            }
            $shipTo = is_array($order['shipTo'] ?? null) ? $order['shipTo'] : [];
            $rows[] = ['ss_order_id' => $this->text($order['orderId'] ?? ''), 'order_number' => $this->text($order['orderNumber'] ?? ''), 'order_date' => substr($this->text($order['orderDate'] ?? ''), 0, 10), 'customer' => $this->text($shipTo['name'] ?? ''), 'email' => $this->text($order['customerEmail'] ?? ''), 'total' => is_numeric($order['orderTotal'] ?? null) ? (float) $order['orderTotal'] : 0.0, 'sh_fulfillment' => $status ?: 'unfulfilled', 'sh_financial' => $this->text($shopify['financial_status'] ?? ''), 'shopify_id' => $this->text($shopify['id'] ?? '')];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['order_date'], $a['order_date']));

        return ['shipped_total' => $shipped, 'rows' => $rows];
    }

}
