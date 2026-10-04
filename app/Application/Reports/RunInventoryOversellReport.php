<?php

namespace App\Application\Reports;

use App\Domain\Reports\InventoryOversellAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Models\Store;
use LogicException;

class RunInventoryOversellReport
{
    public function __construct(
        private readonly ShopifyCatalog $shopify,
        private readonly ShipStationClientFactory $shipStationFactory,
        private readonly InventoryOversellAnalyzer $analyzer,
    ) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store): ReportResult
    {
        $catalogue = $this->shopify->inventoryOversellCandidates($store);
        $shipStation = $this->shipStationFactory->forStore($store);

        if ($shipStation === null) {
            throw new LogicException('ShipStation credentials are required for the inventory oversell report.');
        }

        $orders = $shipStation->fetchAwaitingOrders();

        return new ReportResult(rows: $this->analyzer->analyze($catalogue['products'], $orders), scanned: count($catalogue['products']), pages: $catalogue['pages'], truncated: $catalogue['truncated'], params: [], meta: ['products' => count($catalogue['products']), 'awaitingOrders' => count($orders), 'productPages' => $catalogue['pages'], 'productsTruncated' => $catalogue['truncated']]);
    }
}
