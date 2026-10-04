<?php

namespace App\Application\Reports;

use App\Domain\Reports\ShippingMarginAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use LogicException;

class RunShippingMarginReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyOrders $shopify, private readonly ShippingMarginAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate, float $threshold): ReportResult
    {
        $shipStation = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for the shipping margin report.');
        $shipments = $shipStation->fetchShipmentsByDate($startDate, $endDate);
        $orders = $this->shopify->shippingMarginCandidates($store, $startDate);
        $rows = $this->analyzer->analyze($shipments, $orders['orders'], $threshold);

        return new ReportResult(rows: $rows, scanned: count($shipments), pages: $orders['pages'], truncated: $orders['truncated'], params: ['startDate' => $startDate, 'endDate' => $endDate, 'threshold' => $threshold], meta: ['byCarrier' => $this->analyzer->byCarrier($rows), 'shopifyPages' => $orders['pages'], 'shopifyTruncated' => $orders['truncated']]);
    }
}
