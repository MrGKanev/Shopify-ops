<?php

namespace App\Application\Reports;

use App\Domain\Reports\ItemMismatchAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use LogicException;

class RunItemMismatchReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyAdminGateway $shopify, private readonly ItemMismatchAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): ItemMismatchResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
        $ss = $client->fetchAllOrders($start, $end);
        $sh = $this->shopify->itemMismatchCandidates($store, $start, $end);

        return new ItemMismatchResult($start, $end, count($ss), $this->analyzer->analyze($ss, $sh['orders']), $sh['pages'], $sh['truncated']);
    }
}
