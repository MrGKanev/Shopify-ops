<?php

namespace App\Application\Reports;

use App\Domain\Reports\ItemMismatchAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use LogicException;

class RunItemMismatchReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyOrders $shopify, private readonly ItemMismatchAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
        $ss = $client->fetchAllOrders($start, $end);
        $sh = $this->shopify->itemMismatchCandidates($store, $start, $end);

        return new ReportResult(rows: $this->analyzer->analyze($ss, $sh['orders']), scanned: count($ss), pages: $sh['pages'], truncated: $sh['truncated'], params: ['startDate' => $start, 'endDate' => $end], meta: ['shopifyPages' => $sh['pages'], 'shopifyTruncated' => $sh['truncated']]);
    }
}
