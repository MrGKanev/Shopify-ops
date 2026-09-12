<?php

namespace App\Domain\Reports;

class ShippingMarginAnalyzer
{
    /** @param list<array<string, mixed>> $shipments @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $shipments, array $orders, float $threshold): array
    {
        $orders = array_column($orders, null, 'order_number');
        $rows = [];
        foreach ($shipments as $shipment) {
            $number = preg_replace('/\D/', '', is_scalar($shipment['orderNumber'] ?? null) ? (string) $shipment['orderNumber'] : '');
            $order = $orders[$number] ?? null;
            if (! is_array($order) || ($shipment['voided'] ?? false) === true) {
                continue;
            }
            $shipCost = (float) ($shipment['shipmentCost'] ?? 0) + (float) ($shipment['insuranceCost'] ?? 0);
            $shippingCharged = array_sum(array_map(fn (mixed $line): float => is_array($line) ? (float) ($line['price'] ?? 0) : 0, is_array($order['shipping_lines'] ?? null) ? $order['shipping_lines'] : []));
            $loss = $shipCost - $shippingCharged;
            if ($loss <= $threshold) {
                continue;
            }
            $carrier = trim(is_scalar($shipment['carrierCode'] ?? null) ? (string) $shipment['carrierCode'] : '') ?: 'Unknown';
            $orderId = $shipment['orderId'] ?? null;
            $rows[] = [
                'shopify_id' => $order['id'] ?? '', 'order_number' => is_scalar($shipment['orderNumber'] ?? null) ? (string) $shipment['orderNumber'] : '',
                'ship_date' => substr(is_scalar($shipment['shipDate'] ?? null) ? (string) $shipment['shipDate'] : '', 0, 10), 'carrier' => $carrier,
                'service' => is_scalar($shipment['serviceCode'] ?? null) ? (string) $shipment['serviceCode'] : '', 'ship_cost' => $shipCost,
                'shipping_charged' => $shippingCharged, 'loss' => $loss, 'email' => is_scalar($order['email'] ?? null) ? (string) $order['email'] : '',
                'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0,
                'ss_url' => is_scalar($orderId) && (string) $orderId !== '' ? 'https://app.shipstation.com/#!/orders/order-details/'.rawurlencode((string) $orderId) : null,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['loss'] <=> $a['loss']);

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows @return list<array{carrier: string, count: int, total_loss: float, avg_loss: float}> */
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
