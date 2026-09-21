<?php

namespace App\Application\Reports;

use App\Domain\Reports\DiscountAbuseAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunDiscountAbuseReport extends RunScanReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly DiscountAbuseAnalyzer $analyzer) {}

    /** @return ScanResult<array{code: string, address_name: string, address_line: string, order_count: int, email_count: int, emails: list<string>, total: float|string, orders: list<array<string, mixed>>}> */
    public function handle(Store $store, string $start, string $end, int $minimumEmails): ScanResult
    {
        $result = $this->shopify->discountAbuseCandidates($store, $start, $end);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders'], $minimumEmails), ['startDate' => $start, 'endDate' => $end, 'minimumEmails' => $minimumEmails]);
    }
}
