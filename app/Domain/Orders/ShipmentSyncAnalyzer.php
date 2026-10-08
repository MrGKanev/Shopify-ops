<?php

namespace App\Domain\Orders;

class ShipmentSyncAnalyzer
{
    /**
     * @param  array<string, mixed>  $shipStationOrder
     * @param  array<string, mixed>  $shipment
     * @param  array<string, mixed>  $shopifyOrder
     * @return list<string>
     */
    public function findings(array $shipStationOrder, array $shipment, array $shopifyOrder): array
    {
        $expected = $this->shipmentQuantities($shipStationOrder, $shipment, $shopifyOrder);
        if ($expected === null) {
            return ['Shipment item mapping is incomplete or ambiguous; review the partial shipment manually.'];
        }
        $tracking = trim((string) ($shipment['trackingNumber'] ?? ''));
        $covered = [];
        $tracked = [];
        foreach ($shopifyOrder['fulfillments'] ?? [] as $fulfillment) {
            if (! is_array($fulfillment) || ($fulfillment['status'] ?? '') !== 'success') {
                continue;
            }
            $numbers = [...($fulfillment['tracking_numbers'] ?? []), $fulfillment['tracking_number'] ?? ''];
            $hasTracking = $tracking !== '' && in_array($tracking, $numbers, true);
            foreach ($fulfillment['line_items'] ?? [] as $item) {
                $id = (string) ($item['id'] ?? '');
                $quantity = (int) ($item['quantity'] ?? 0);
                $covered[$id] = ($covered[$id] ?? 0) + $quantity;
                if ($hasTracking) {
                    $tracked[$id] = ($tracked[$id] ?? 0) + $quantity;
                }
            }
        }
        $findings = [];
        foreach ($expected as $id => $quantity) {
            if (($covered[$id] ?? 0) < $quantity) {
                $findings[] = 'Shopify fulfillment does not cover the shipped items and quantities.';
                break;
            }
        }
        if ($tracking === '') {
            $findings[] = 'ShipStation shipment has no tracking number to verify.';
        } else {
            foreach ($expected as $id => $quantity) {
                if (($tracked[$id] ?? 0) < $quantity) {
                    $findings[] = 'Shopify tracking does not cover this shipment’s items and quantities.';
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $shipment
     * @param  array<string, mixed>  $shopify
     * @return array<string, int>|null
     */
    private function shipmentQuantities(array $order, array $shipment, array $shopify): ?array
    {
        $items = $shipment['shipmentItems'] ?? [];
        if (! is_array($items) || $items === [] || ($order['advancedOptions']['mergedOrSplit'] ?? false)) {
            return null;
        }
        $quantities = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_int($item['quantity'] ?? null) || $item['quantity'] < 1) {
                return null;
            }
            $orderItems = array_values(array_filter($order['items'] ?? [], fn (array $candidate): bool => ! empty($item['orderItemId'])
                ? (string) ($candidate['orderItemId'] ?? '') === (string) $item['orderItemId']
                : ! empty($item['sku']) && ($candidate['sku'] ?? null) === $item['sku']));
            if (count($orderItems) !== 1) {
                return null;
            }
            $key = (string) ($orderItems[0]['lineItemKey'] ?? '');
            $sku = (string) ($orderItems[0]['sku'] ?? '');
            $matches = array_values(array_filter($shopify['line_items'] ?? [], fn (array $candidate): bool => $key !== ''
                ? (string) ($candidate['id'] ?? '') === $key || ($candidate['admin_graphql_api_id'] ?? '') === $key
                : $sku !== '' && ($candidate['sku'] ?? '') === $sku));
            if (count($matches) !== 1 || empty($matches[0]['id'])) {
                return null;
            }
            $id = (string) $matches[0]['id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + $item['quantity'];
            if ($quantities[$id] > (int) ($matches[0]['quantity'] ?? 0)) {
                return null;
            }
        }

        return $quantities;
    }
}
