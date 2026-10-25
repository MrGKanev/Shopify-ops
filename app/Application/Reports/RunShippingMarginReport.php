<?php

namespace App\Application\Reports;

use App\Application\Orders\VerifyShipStationShipmentOwnership;
use App\Domain\Reports\ShippingMarginAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use LogicException;

class RunShippingMarginReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyOrders $shopify, private readonly ShippingMarginAnalyzer $analyzer, private readonly ShippingBillingCosts $billing, private readonly VerifyShipStationShipmentOwnership $ownership) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate, float $threshold): ReportResult
    {
        $shipStation = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for the shipping margin report.');
        $storeId = $this->ownership->storeId($store);
        $shipments = $this->ownership->handle($store, $shipStation, $shipStation->fetchShipmentsByDate($startDate, $endDate, $storeId));
        $orders = $this->shopify->shippingMarginCandidates($store, $startDate);
        $billing = $this->billing->forShipments($store, array_values(array_filter(array_map(fn (array $shipment): string => (string) ($shipment['shipmentId'] ?? ''), $shipments))));
        $byNumber = array_column($orders['orders'], null, 'order_number');
        foreach ($shipments as &$shipment) {
            $number = ltrim(trim((string) ($shipment['orderNumber'] ?? '')), '#');
            $currency = $byNumber[$number]['currency'] ?? null;
            if (! $billing['truncated'] && is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency)) {
                $group = $this->billing->match($billing['groups'], (string) ($shipment['shipmentId'] ?? ''), (string) ($shipment['orderId'] ?? ''), $number, $currency);
                if ($group !== null && $group['billed_cost'] !== null) {
                    $shipment['imported_billing_cost'] = $group['billed_cost'];
                    $shipment['imported_billing_currency'] = $currency;
                }
            }
        }
        unset($shipment);
        $rows = $this->analyzer->analyze($shipments, $orders['orders'], $threshold);

        return new ReportResult(rows: $rows, scanned: count($shipments), pages: $orders['pages'], truncated: $orders['truncated'], params: ['startDate' => $startDate, 'endDate' => $endDate, 'threshold' => $threshold], meta: ['byCarrier' => $this->analyzer->byCarrier($rows), 'shopifyPages' => $orders['pages'], 'shopifyTruncated' => $orders['truncated']]);
    }
}
