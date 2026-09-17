<?php

namespace App\Application\Reports;

use App\Domain\Reports\DuplicateAddressAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunDuplicateAddressReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly DuplicateAddressAnalyzer $analyzer) {}

    /** @return ScanResult<array{address_line: string, address_name: string, order_count: int, emails: list<string>, orders: list<array<string, mixed>>}> */
    public function handle(Store $store, string $start, string $end): ScanResult
    {
        $result = $this->shopify->addressCheckCandidates($store, $start, $end, false);

        return new ScanResult(scanned: count($result['orders']), rows: $this->analyzer->analyze($result['orders']), pages: $result['pages'], truncated: $result['truncated'], startDate: $start, endDate: $end);
    }
}
