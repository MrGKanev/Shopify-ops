<?php

namespace App\Application\Reports;

use App\Domain\Reports\DiscountAbuseAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunDiscountAbuseReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly DiscountAbuseAnalyzer $analyzer) {}

    /** @return ScanResult<array{code: string, address_name: string, address_line: string, order_count: int, email_count: int, emails: list<string>, total: float|string, orders: list<array<string, mixed>>}> */
    public function handle(Store $store, string $start, string $end, int $minimumEmails): ScanResult
    {
        $result = $this->shopify->discountAbuseCandidates($store, $start, $end);

        return new ScanResult(scanned: count($result['orders']), rows: $this->analyzer->analyze($result['orders'], $minimumEmails), pages: $result['pages'], truncated: $result['truncated'], startDate: $start, endDate: $end, minimumEmails: $minimumEmails);
    }
}
