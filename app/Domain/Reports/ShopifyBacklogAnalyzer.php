<?php

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;
use UnexpectedValueException;

class ShopifyBacklogAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $fulfillmentOrders
     * @return array{rows: list<array<string, mixed>>, orders: int, fulfillment_orders: int, remaining_units: int, unpriced_units: int, currencies: list<array<string, mixed>>}
     */
    public function analyze(array $fulfillmentOrders): array
    {
        $rows = $orders = $seen = $seenItems = $currencies = [];
        $units = $unpriced = 0;
        foreach ($fulfillmentOrders as $fulfillment) {
            $id = $fulfillment['id'] ?? null;
            $status = $fulfillment['status'] ?? null;
            $order = $fulfillment['order'] ?? null;
            if (! is_string($id) || ! preg_match('~^gid://shopify/FulfillmentOrder/[1-9][0-9]*$~D', $id) || isset($seen[$id]) || ! in_array($status, ['OPEN', 'IN_PROGRESS', 'ON_HOLD', 'SCHEDULED', 'INCOMPLETE', 'CLOSED', 'CANCELLED'], true) || ! is_array($order)) {
                throw new UnexpectedValueException('Shopify backlog fulfillment identity or status could not be confirmed.');
            }
            $seen[$id] = true;
            $orderId = $order['id'] ?? null;
            $currency = $order['presentmentCurrencyCode'] ?? null;
            if (! is_string($orderId) || ! preg_match('~^gid://shopify/Order/[1-9][0-9]*$~D', $orderId) || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency) || ! is_string($order['name'] ?? null) || ! is_bool($order['test'] ?? null) || ! is_bool($order['taxesIncluded'] ?? null) || ! array_key_exists('cancelledAt', $order) || ($order['cancelledAt'] !== null && (! is_string($order['cancelledAt']) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $order['cancelledAt'])))) {
                throw new UnexpectedValueException('Shopify backlog parent order could not be confirmed.');
            }
            if ($order['test'] || $order['cancelledAt'] !== null || in_array($status, ['CLOSED', 'CANCELLED'], true)) {
                continue;
            }
            $items = $fulfillment['lineItems']['nodes'] ?? null;
            if (! is_array($items) || ! array_is_list($items) || ($fulfillment['lineItems']['pageInfo']['hasNextPage'] ?? null) !== false) {
                throw new UnexpectedValueException('Shopify backlog line items are incomplete.');
            }
            $remaining = $missingPrice = 0;
            $value = BigDecimal::of(0);
            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null) || ! preg_match('~^gid://shopify/FulfillmentOrderLineItem/[1-9][0-9]*$~D', $item['id']) || isset($seenItems[$item['id']]) || ! is_bool($item['requiresShipping'] ?? null) || ! is_int($item['remainingQuantity'] ?? null) || ! is_int($item['totalQuantity'] ?? null) || $item['remainingQuantity'] < 0 || $item['totalQuantity'] < $item['remainingQuantity']) {
                    throw new UnexpectedValueException('Shopify backlog remaining quantities could not be confirmed.');
                }
                $seenItems[$item['id']] = true;
                if (! $item['requiresShipping'] || $item['remainingQuantity'] === 0) {
                    continue;
                }
                $quantity = $item['remainingQuantity'];
                $remaining += $quantity;
                $money = $item['lineItem']['discountedUnitPriceAfterAllDiscountsSet']['presentmentMoney'] ?? null;
                if (! is_array($money) || ($money['currencyCode'] ?? null) !== $currency || ! is_string($money['amount'] ?? null) || ! preg_match('/^\d{1,18}(?:\.\d{1,12})?$/D', $money['amount'])) {
                    $missingPrice += $quantity;

                    continue;
                }
                $value = $value->plus(BigDecimal::of($money['amount'])->multipliedBy($quantity));
            }
            if ($remaining === 0) {
                continue;
            }
            if (isset($orders[$orderId]) && $orders[$orderId] !== $currency) {
                throw new UnexpectedValueException('Shopify backlog order currency changed during the scan.');
            }
            $orders[$orderId] = $currency;
            $units += $remaining;
            $unpriced += $missingPrice;
            $rows[] = ['fulfillment_order_id' => $id, 'order_id' => $orderId, 'order_number' => $order['name'], 'status' => $status, 'currency' => $currency, 'remaining_units' => $remaining, 'estimated_value' => $missingPrice === 0 ? (string) $value : null, 'known_value' => (string) $value, 'unpriced_units' => $missingPrice, 'taxes_included' => $order['taxesIncluded']];
            $summary = $currencies[$currency] ?? ['currency' => $currency, 'orders' => [], 'fulfillment_orders' => 0, 'remaining_units' => 0, 'unpriced_units' => 0, 'known_value' => BigDecimal::of(0)];
            $summary['orders'][$orderId] = true;
            $summary['fulfillment_orders']++;
            $summary['remaining_units'] += $remaining;
            $summary['unpriced_units'] += $missingPrice;
            $summary['known_value'] = $summary['known_value']->plus($value);
            $currencies[$currency] = $summary;
        }
        ksort($currencies);
        $summaries = [];
        foreach ($currencies as $summary) {
            $summary['orders'] = count($summary['orders']);
            $summary['known_value'] = (string) $summary['known_value'];
            $summary['estimated_value'] = $summary['unpriced_units'] === 0 ? $summary['known_value'] : null;
            $summaries[] = $summary;
        }
        usort($rows, fn (array $left, array $right): int => [$left['order_number'], $left['fulfillment_order_id']] <=> [$right['order_number'], $right['fulfillment_order_id']]);

        return ['rows' => $rows, 'orders' => count($orders), 'fulfillment_orders' => count($rows), 'remaining_units' => $units, 'unpriced_units' => $unpriced, 'currencies' => $summaries];
    }
}
