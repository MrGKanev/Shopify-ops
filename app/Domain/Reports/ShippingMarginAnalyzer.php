<?php

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;

class ShippingMarginAnalyzer
{
    public function __construct(private readonly ExactDecimalAmount $amounts = new ExactDecimalAmount) {}

    /**
     * @param  list<array<string, mixed>>  $shipments
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    public function analyze(array $shipments, array $orders, float $threshold): array
    {
        $byNumber = [];
        foreach ($orders as $order) {
            $number = ltrim(trim((string) ($order['order_number'] ?? '')), '#');
            $byNumber[$number][] = $order;
        }
        $groups = [];
        foreach ($shipments as $shipment) {
            $number = ltrim(trim((string) ($shipment['orderNumber'] ?? '')), '#');
            if (count($byNumber[$number] ?? []) === 1 && ($shipment['voided'] ?? false) !== true) {
                $groups[$number][] = $shipment;
            }
        }
        $rows = [];
        foreach ($groups as $number => $labels) {
            $order = $byNumber[$number][0];
            $labelCost = 0.0;
            $importedCost = BigDecimal::zero();
            $allImported = true;
            $currencies = $carriers = $services = $dates = $seen = [];
            foreach ($labels as $shipment) {
                $labelCurrency = strtoupper((string) ($shipment['currencyCode'] ?? $shipment['currency'] ?? ''));
                if ($labelCurrency !== '' && isset($order['currency']) && $labelCurrency !== $order['currency']) {
                    continue 2;
                }
                $id = (string) ($shipment['shipmentId'] ?? '');
                if ($id !== '' && isset($seen[$id])) {
                    if ($seen[$id] !== $shipment) {
                        continue 2;
                    }

                    continue;
                }
                if ($id !== '') {
                    $seen[$id] = $shipment;
                }
                $labelCost += (float) ($shipment['shipmentCost'] ?? 0) + (float) ($shipment['insuranceCost'] ?? 0);
                if (! isset($shipment['imported_billing_cost'], $shipment['imported_billing_currency'])) {
                    $allImported = false;
                } else {
                    $importedCost = $importedCost->plus($shipment['imported_billing_cost']);
                    $currencies[] = $shipment['imported_billing_currency'];
                }
                $carriers[] = trim((string) ($shipment['carrierCode'] ?? '')) ?: 'Unknown';
                $services[] = (string) ($shipment['serviceCode'] ?? '');
                $dates[] = substr((string) ($shipment['shipDate'] ?? ''), 0, 10);
            }
            $useImported = $allImported && count(array_unique($currencies)) === 1;
            $shipCost = $useImported ? (float) (string) $importedCost : $labelCost;
            if (! is_array($order['shipping_lines'] ?? null)) {
                continue;
            }
            $shippingAmount = BigDecimal::zero();
            foreach ($order['shipping_lines'] as $line) {
                $price = is_array($line) ? $this->amounts->parse($line['price'] ?? null) : null;
                if ($price === null) {
                    continue 2;
                }
                $shippingAmount = $shippingAmount->plus($price);
            }
            $shippingCharged = (float) (string) $shippingAmount;
            $loss = $shipCost - $shippingCharged;
            $importedLoss = $useImported ? $importedCost->minus($shippingAmount) : null;
            if ($importedLoss !== null ? $importedLoss->isLessThanOrEqualTo((string) $threshold) : $loss <= $threshold) {
                continue;
            }
            $orderId = $labels[0]['orderId'] ?? null;
            sort($dates);
            $rows[] = [
                'shopify_id' => $order['id'] ?? '', 'order_number' => (string) $number, 'ship_date' => $dates[0],
                'carrier' => implode(' + ', array_unique($carriers)), 'service' => implode(' + ', array_unique($services)),
                'ship_cost' => $shipCost, 'label_cost' => $labelCost, 'imported_cost' => $useImported ? (string) $importedCost : null,
                'imported_loss' => $importedLoss === null ? null : (string) $importedLoss, 'billing_currency' => $useImported ? $currencies[0] : null,
                'shipping_charged' => $shippingCharged, 'shipping_charged_exact' => (string) $shippingAmount, 'loss' => $loss, 'email' => is_scalar($order['email'] ?? null) ? (string) $order['email'] : '',
                'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0,
                'ss_url' => is_scalar($orderId) && (string) $orderId !== '' ? 'https://app.shipstation.com/#!/orders/order-details/'.rawurlencode((string) $orderId) : null,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['loss'] <=> $a['loss']);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{carrier: string, count: int, total_loss: float, avg_loss: float}>
     */
    public function byCarrier(array $rows): array
    {
        $carriers = [];
        foreach ($rows as $row) {
            $carrier = $row['carrier'];
            $carriers[$carrier] ??= ['carrier' => $carrier, 'count' => 0, 'total_loss' => 0.0];
            $carriers[$carrier]['count']++;
            $carriers[$carrier]['total_loss'] += $row['loss'];
        }
        $summary = array_map(fn (array $row): array => ['carrier' => $row['carrier'], 'count' => $row['count'], 'total_loss' => round($row['total_loss'], 2), 'avg_loss' => round($row['total_loss'] / $row['count'], 2)], array_values($carriers));
        usort($summary, fn (array $a, array $b): int => $b['total_loss'] <=> $a['total_loss']);

        return $summary;
    }
}
