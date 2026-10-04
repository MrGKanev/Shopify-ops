<?php

namespace App\Application\Reports;

use App\Domain\Reports\OrphanOrderAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use LogicException;

class RunOrphanOrderReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyOrders $shopify, private readonly OrphanOrderAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
        $ss = $client->fetchAllOrders($start, $end);
        $sh = $this->shopify->itemMismatchCandidates($store, $start, $end);

        return new ReportResult(rows: $this->analyzer->analyze($ss, $sh['orders']), scanned: count($sh['orders']), pages: $sh['pages'], truncated: $sh['truncated'], params: ['startDate' => $start, 'endDate' => $end], meta: ['shipStationTotal' => count($ss), 'shopifyTotal' => count($sh['orders']), 'shopifyPages' => $sh['pages'], 'shopifyTruncated' => $sh['truncated']]);
    }
}
