<?php

namespace App\Domain\Orders;

class PreShipmentDriftAnalyzer
{
    public function __construct(private readonly OrderChannelComparator $comparator = new OrderChannelComparator) {}

    /**
     * Compares what ShipStation will still ship for an order number with what Shopify still needs shipped.
     *
     * ShipStation open orders sharing a number are summed, so a valid split or partial order is judged on its combined remaining quantity and never against the whole original order. Anything that cannot be confirmed is reported as unknown, never as a match.
     *
     * @param  array<string, list<array{id: string, status: string, skus: array<string, int>, unidentified_lines: bool, has_customs: bool, address: array<string, string|null>}>>  $shipStation  open ShipStation orders keyed by order number
     * @param  array<string, list<array{id: string, cancelled: bool, complete: bool, has_fulfillment_orders: bool, remaining: array<string, int>, unidentified_lines: bool, address: array<string, string|null>}>>  $shopify  keyed by order number
     * @return list<array{number: string, state: string, reasons: list<string>, extra: array<string, int>, missing: array<string, int>, shipstation_orders: int, statuses: list<string>, address_changes: list<array{field: string, shopify: string, shipstation: string, kind: string}>, flags: list<string>}>
     */
    public function analyze(array $shipStation, array $shopify): array
    {
        $rows = [];
        foreach ($shipStation as $number => $orders) {
            $number = (string) $number;
            $candidates = $shopify[$number] ?? [];
            $row = ['number' => $number, 'state' => 'unknown', 'reasons' => [], 'extra' => [], 'missing' => [], 'shipstation_orders' => count($orders), 'statuses' => array_values(array_unique(array_column($orders, 'status'))), 'address_changes' => [], 'flags' => []];
            sort($row['statuses']);
            if (count($candidates) !== 1) {
                $row['reasons'][] = $candidates === [] ? 'shopify_order_not_found' : 'shopify_order_ambiguous';
                $rows[] = $row;

                continue;
            }
            $order = $candidates[0];
            $incomplete = ! $order['complete'];
            $unidentified = $order['unidentified_lines'] || in_array(true, array_column($orders, 'unidentified_lines'), true);
            if ($incomplete) {
                $row['reasons'][] = 'shopify_lines_incomplete';
            } elseif (! $order['has_fulfillment_orders']) {
                $row['reasons'][] = 'shopify_fulfillment_orders_unavailable';
            }
            $open = [];
            foreach ($orders as $shipStationOrder) {
                foreach ($shipStationOrder['skus'] as $sku => $quantity) {
                    $open[$sku] = ($open[$sku] ?? 0) + $quantity;
                }
            }
            if ($row['reasons'] === []) {
                foreach ($open as $sku => $quantity) {
                    $delta = $quantity - ($order['remaining'][$sku] ?? 0);
                    if ($delta > 0) {
                        $row['extra'][$sku] = $delta;
                    }
                }
                foreach ($order['remaining'] as $sku => $quantity) {
                    $delta = $quantity - ($open[$sku] ?? 0);
                    if ($delta > 0) {
                        $row['missing'][$sku] = $delta;
                    }
                }
                ksort($row['extra']);
                ksort($row['missing']);
            }
            if ($order['cancelled']) {
                $row['flags'][] = 'shopify_cancelled';
            }
            $row['address_changes'] = count($orders) === 1 ? $this->comparator->addressDifferences($order['address'], $orders[0]['address']) : [];
            if (count($orders) > 1) {
                $row['flags'][] = 'split_in_shipstation';
            }
            if ($row['extra'] !== [] || $row['missing'] !== []) {
                $row['flags'][] = 'weight_review';
                if (in_array(true, array_column($orders, 'has_customs'), true)) {
                    $row['flags'][] = 'customs_review';
                }
            }
            $substantive = array_filter($row['address_changes'], fn (array $change): bool => $change['kind'] === 'substantive');
            $row['state'] = match (true) {
                $row['reasons'] !== [] => 'unknown',
                $row['extra'] !== [] || $row['missing'] !== [] || $order['cancelled'] => 'different',
                $substantive !== [] => 'address',
                $unidentified => 'unknown',
                default => 'match',
            };
            if ($row['state'] === 'unknown' && $row['reasons'] === []) {
                $row['reasons'][] = 'lines_without_sku';
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
