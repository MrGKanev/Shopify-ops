<?php

namespace App\Application\Reports;

use App\Domain\Reports\ProductCompletenessAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Models\Store;

class RunProductCompletenessReport
{
    public function __construct(private readonly ShopifyCatalog $shopify, private readonly ProductCompletenessAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store): ReportResult
    {
        $result = $this->shopify->productCompletenessCandidates($store);
        $rows = $this->analyzer->analyze($result['products']);

        return new ReportResult(rows: $rows, scanned: count($result['products']), pages: $result['pages'], truncated: $result['truncated'], params: [], meta: ['critical' => count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'critical')), 'warnings' => count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'warning'))]);
    }
}
