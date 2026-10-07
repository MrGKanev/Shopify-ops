<?php

namespace App\Application\Orders;

use App\Domain\Reports\CustomsReadinessAnalyzer;
use App\Integrations\Shopify\ShopifyCustoms;
use App\Models\Store;
use Throwable;

class CheckOrderCustoms
{
    public function __construct(private readonly LoadOrderForRemediation $orders, private readonly ShopifyCustoms $shopify, private readonly CustomsReadinessAnalyzer $analyzer) {}

    /** @return array<string, mixed> */
    public function handle(Store $store, string $number): array
    {
        $base = $this->orders->shopify($store, $number);
        $order = [];
        $lines = [];
        $notes = [];
        $complete = false;
        try {
            $data = $this->shopify->order($store, 'gid://shopify/Order/'.$base['id']);
            $order = $data['order'];
            $complete = ! $data['truncated'];
            foreach ($data['lines'] as $line) {
                if (($line['requiresShipping'] ?? null) === false) {
                    continue;
                }
                if (! is_int($line['unfulfilledQuantity'] ?? null) || ! is_int($line['currentQuantity'] ?? null) || ! is_bool($line['requiresShipping'] ?? null)) {
                    $complete = false;

                    continue;
                }
                $quantity = min($line['unfulfilledQuantity'], $line['currentQuantity']);
                if ($quantity > 0) {
                    $lines[] = [...$line, 'pending_quantity' => $quantity];
                }
            }
        } catch (Throwable) {
            $notes[] = 'Shopify customs item data is unavailable. Check product/inventory scopes and permissions.';
        }
        $ss = null;
        $products = [];
        $ssComplete = false;
        $from = '';
        try {
            $client = $this->orders->client($store);
            $ss = $this->orders->shipStation($store, $base);
            try {
                $defaults = $client->customsProducts();
                $products = $defaults['products'];
                $ssComplete = ! $defaults['truncated'];
            } catch (Throwable) {
                $notes[] = 'ShipStation product defaults could not be loaded.';
            }
            $warehouseId = data_get($ss, 'advancedOptions.warehouseId');
            if (is_scalar($warehouseId) && ctype_digit((string) $warehouseId) && (int) $warehouseId > 0) {
                $warehouse = $client->getWarehouse((int) $warehouseId);
                if ((string) ($warehouse['warehouseId'] ?? '') === (string) $warehouseId) {
                    $from = strtoupper(is_string($warehouse['originAddress']['country'] ?? null) ? $warehouse['originAddress']['country'] : '');
                }
            }
        } catch (Throwable) {
            $notes[] = 'ShipStation order or warehouse lookup could not be completed.';
        }
        $rows = [];
        foreach ($lines as $line) {
            $variant = is_array($line['variant'] ?? null) ? $line['variant'] : [];
            $variant = [...$variant, 'id' => $line['id'], 'sku' => $line['sku'] ?? $variant['sku'] ?? '', 'customs_title' => $line['title'] ?? '', 'price' => data_get($line, 'originalUnitPriceSet.shopMoney.amount')];
            if (isset($variant['inventoryItem']) && is_array($variant['inventoryItem'])) {
                if (($variant['inventoryItem']['requiresShipping'] ?? null) === false) {
                    $notes[] = 'Current product shipping requirement differs from the pending order item.';
                }
                $variant['inventoryItem']['requiresShipping'] = $line['requiresShipping'];
            }
            if (! isset($variant['inventoryItem'])) {
                $variant['inventoryItem'] = ['requiresShipping' => $line['requiresShipping']];
            }
            $currency = data_get($line, 'originalUnitPriceSet.shopMoney.currencyCode');
            $row = $this->analyzer->variant($variant, $products, $ssComplete, is_string($currency) ? $currency : '');
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        $shipment = $this->analyzer->shipment($order, $lines, $rows, $ss, $from, $complete, $complete && $this->analyzer->matchesPendingItems($lines, $ss['items'] ?? null));
        $rows = $this->analyzer->preparedCoverage($rows, $shipment['declarations']);
        $shopifyTo = data_get($order, 'shippingAddress.countryCodeV2');
        if (is_string($shopifyTo) && $shipment['to'] !== '' && strtoupper($shopifyTo) !== $shipment['to']) {
            $notes[] = 'Shopify and ShipStation destination countries differ.';
        }

        return [
            'rows' => $rows, 'declarations' => $shipment['declarations'], 'findings' => array_values(array_unique([...$notes, ...$shipment['findings']])),
            'from' => $shipment['from'], 'to' => $shipment['to'],
            'carrier' => is_string($ss['carrierCode'] ?? null) ? $ss['carrierCode'] : '',
            'service' => is_string($ss['serviceCode'] ?? null) ? $ss['serviceCode'] : '',
            'checked_at' => now($store->shopTimezone())->toIso8601String(), 'next_after' => null, 'mode' => 'order',
            'order_number' => $base['name'] ?? $number, 'partial' => ! $complete || ! $ssComplete || $ss === null || ! $this->analyzer->validCountry($from) || ! $this->analyzer->validCountry($shipment['to']),
        ];
    }
}
