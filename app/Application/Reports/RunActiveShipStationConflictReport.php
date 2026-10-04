<?php

namespace App\Application\Reports;

use App\Domain\Reports\ActiveShipStationConflictAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use LogicException;

class RunActiveShipStationConflictReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyOrders $shopify, private readonly ActiveShipStationConflictAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
        $ss = $client->fetchActiveOrders();
        $sh = $this->shopify->itemMismatchCandidates($store, $start, $end);
        $data = $this->analyzer->analyze($sh['orders'], $ss);

        return new ReportResult(rows: $data['rows'], scanned: $data['scanned'], pages: $sh['pages'], truncated: $sh['truncated'], params: ['startDate' => $start, 'endDate' => $end], meta: ['activeShipStation' => count($ss), 'shopifyPages' => $sh['pages'], 'shopifyTruncated' => $sh['truncated']]);
    }
}
