<?php

namespace App\Application\Reports;

use App\Domain\Reports\PayoutAnomalyAnalyzer;
use App\Integrations\Shopify\ShopifyPayouts;
use App\Models\Store;
use Carbon\CarbonImmutable;

class RunFinancialExceptionsReport
{
    public function __construct(private readonly ShopifyPayouts $shopify, private readonly PayoutAnomalyAnalyzer $analyzer) {}

    /** @param array<string, mixed> $input
     * @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, array $input): ReportResult
    {
        if (empty($input['payout_id'])) {
            $choices = $this->shopify->choices($store, $input['after'] ?? null);

            return new ReportResult(rows: [], scanned: count($choices['payouts']), params: $input, meta: ['mode' => 'select', ...$choices]);
        }
        $data = $this->shopify->collect($store, (string) $input['payout_id']);
        $analysis = $this->analyzer->analyze($data, $input, CarbonImmutable::now());

        return new ReportResult(rows: $analysis['rows'], scanned: count($data['transactions']), pages: $data['pages'], truncated: ! $analysis['summary']['complete'], params: $input, meta: ['mode' => 'analysis', 'summary' => $analysis['summary'], 'transactions' => $analysis['transactions']]);
    }
}
