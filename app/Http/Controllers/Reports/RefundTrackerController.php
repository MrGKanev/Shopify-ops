<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunRefundTrackerReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RefundTrackerController extends Controller
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
        $result = null;
        $reportFailed = false;

        if (! $shopifyConfigurationError && ! $shipStationConfigurationWarning) {
            $run = $reports->run($request, $store, 'refund_tracker', $report::class, [$startDate, $endDate], $startDate, $endDate);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.refund-tracker', $this->viewData($startDate, $endDate, $result, $reportFailed, $shopifyConfigurationError, $shipStationConfigurationWarning));
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
