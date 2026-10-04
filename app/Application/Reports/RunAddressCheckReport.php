<?php

namespace App\Application\Reports;

use App\Domain\Reports\AddressCheckAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunAddressCheckReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly AddressCheckAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end, bool $poBoxOnly, bool $unfulfilledOnly): ReportResult
    {
        $result = $this->shopify->addressCheckCandidates($store, $start, $end, $unfulfilledOnly);
        $rows = $this->analyzer->analyze($result['orders'], $poBoxOnly);

        return new ReportResult(rows: $rows, scanned: count($result['orders']), pages: $result['pages'], truncated: $result['truncated'], params: ['startDate' => $start, 'endDate' => $end, 'poBoxOnly' => $poBoxOnly, 'unfulfilledOnly' => $unfulfilledOnly], meta: ['critical' => count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'critical')), 'warnings' => count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'warning'))]);
    }
}
