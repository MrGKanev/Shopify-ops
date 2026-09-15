<?php

namespace App\Application\Reports;

use App\Domain\Reports\OrphanOrderAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use LogicException;

class RunOrphanOrderReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyAdminGateway $shopify, private readonly OrphanOrderAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): OrphanOrderResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
        $ss = $client->fetchAllOrders($start, $end);
        $sh = $this->shopify->itemMismatchCandidates($store, $start, $end);

        return new OrphanOrderResult($start, $end, count($ss), count($sh['orders']), $this->analyzer->analyze($ss, $sh['orders']), $sh['pages'], $sh['truncated']);
    }
}
