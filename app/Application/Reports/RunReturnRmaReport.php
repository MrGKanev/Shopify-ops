<?php

namespace App\Application\Reports;

use App\Application\Operations\SyncReturnExceptionIssues;
use App\Domain\Reports\ReturnExceptionAnalyzer;
use App\Domain\Reports\ReturnRmaAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Integrations\Shopify\ShopifyReturns;
use App\IssueStatus;
use App\Models\Store;
use Carbon\CarbonImmutable;

class RunReturnRmaReport
{
    public function __construct(private readonly ShopifyPayments $shopify, private readonly ReturnRmaAnalyzer $analyzer, private readonly ShopifyReturns $returns, private readonly ReturnExceptionAnalyzer $exceptions, private readonly SyncReturnExceptionIssues $issues) {}

    /** @param array{approval_days: int, processing_days: int, exchange_days: int}|null $policy
     * @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate, ?array $policy = null): ReportResult
    {
        $candidates = $this->shopify->refundTrackerCandidates($store, $startDate, $endDate);
        $analysis = $this->analyzer->analyze($candidates['orders']);

        $policy ??= $store->returnExceptionPolicy();
        $knownReturns = [];
        foreach ($store->operationalIssues()->where('source_tool', 'return_exceptions')->whereIn('status', IssueStatus::active())->whereNotNull('reference')->pluck('reference') as $reference) {
            if (is_string($reference)) {
                $knownReturns[] = $reference;
            }
        }
        $returns = $this->returns->candidates($store, $startDate, $endDate, $knownReturns);
        $checkedAt = CarbonImmutable::now($store->shopTimezone());
        $rows = $this->exceptions->analyze($returns['returns'], $policy, $checkedAt);
        $this->issues->handle($store, $rows, array_column($returns['returns'], 'id'), $startDate, $endDate);

        return new ReportResult(rows: $rows, scanned: count($returns['returns']), pages: $candidates['pages'] + $returns['pages'], truncated: $candidates['truncated'] || $returns['truncated'], params: ['startDate' => $startDate, 'endDate' => $endDate], meta: ['skuStats' => $analysis['sku_stats'], 'refundRows' => $analysis['rows'], 'refundOrderCount' => count($candidates['orders']), 'policy' => $policy, 'checkedAt' => $checkedAt->toIso8601String(), 'warehouseConfirmed' => count(array_filter($rows, fn (array $row): bool => $row['receipt_confirmed']))]);
    }
}
