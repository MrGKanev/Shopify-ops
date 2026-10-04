<?php

namespace App\Application\Reports;

use App\Domain\Reports\ShippedUnfulfilledAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use LogicException;

class RunShippedUnfulfilledReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyOrders $shopify, private readonly ShippedUnfulfilledAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
        $ss = $client->fetchAllOrders($start, $end);
        $sh = $this->shopify->itemMismatchCandidates($store, $start, $end);
        $data = $this->analyzer->analyze($ss, $sh['orders']);

        return new ReportResult(rows: $data['rows'], scanned: $data['shipped_total'], pages: $sh['pages'], truncated: $sh['truncated'], params: ['startDate' => $start, 'endDate' => $end], meta: ['shippedTotal' => $data['shipped_total'], 'shopifyPages' => $sh['pages'], 'shopifyTruncated' => $sh['truncated']]);
    }
}
