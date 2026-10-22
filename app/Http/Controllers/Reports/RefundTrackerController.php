<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunRefundTrackerReport;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RefundTrackerController extends ReportController
{
    public function create(): View
    {
        return view('reports.refund-tracker', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunRefundTrackerReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $shopifyConfigurationError = $store->missingShopifyCredentials();
        $shipStationConfigurationWarning = (trim((string) $store->shipstation_api_key) === '') !== (trim((string) $store->shipstation_api_secret) === '');

        return $this->runQueued($reports, $request, $store, $shopifyConfigurationError || $shipStationConfigurationWarning, 'refund_tracker', $report::class, [$startDate, $endDate], $startDate, $endDate, fn (?ReportResult $result, bool $failed) => view('reports.refund-tracker', $this->viewData($startDate, $endDate, $result, $failed, $shopifyConfigurationError, $shipStationConfigurationWarning)));
    }

    /**
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @return array<string, mixed>
     */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?ReportResult $result = null, bool $reportFailed = false, bool $shopifyConfigurationError = false, bool $shipStationConfigurationWarning = false): array
    {
        return compact('result', 'reportFailed', 'shopifyConfigurationError', 'shipStationConfigurationWarning') + [
            'startDate' => $startDate ?? now()->subDays(30)->toDateString(),
            'endDate' => $endDate ?? now()->toDateString(),
        ];
    }
}
