<?php

namespace App\Application\Reports;

use App\Domain\Reports\ShippingMarginAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use LogicException;

class RunShippingMarginReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyAdminGateway $shopify, private readonly ShippingMarginAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate, float $threshold): ShippingMarginResult
    {
        $shipStation = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for the shipping margin report.');
        $shipments = $shipStation->fetchShipmentsByDate($startDate, $endDate);
        $orders = $this->shopify->shippingMarginCandidates($store, $startDate);
        $rows = $this->analyzer->analyze($shipments, $orders['orders'], $threshold);

        return new ShippingMarginResult($startDate, $endDate, $threshold, count($shipments), $rows, $this->analyzer->byCarrier($rows), $orders['pages'], $orders['truncated']);
    }
}
