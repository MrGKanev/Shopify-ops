<?php

namespace App\Application\Reports;

use App\Domain\Reports\DuplicateAddressAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunDuplicateAddressReport extends RunScanReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly DuplicateAddressAnalyzer $analyzer) {}

    /** @return ScanResult<array{address_line: string, address_name: string, order_count: int, emails: list<string>, orders: list<array<string, mixed>>}> */
    public function handle(Store $store, string $start, string $end): ScanResult
    {
        $result = $this->shopify->addressCheckCandidates($store, $start, $end, false);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders']), ['startDate' => $start, 'endDate' => $end]);
    }
}
