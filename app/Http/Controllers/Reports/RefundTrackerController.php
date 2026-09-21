<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RefundTrackerResult;
use App\Application\Reports\RunRefundTrackerReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\RefundTrackerRequest;
use Illuminate\View\View;
use Throwable;

class RefundTrackerController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.refund-tracker', $this->viewData());
    }

    public function store(RefundTrackerRequest $request, RunRefundTrackerReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $shopifyConfigurationError = $store->missingShopifyCredentials();
        $shipStationConfigurationWarning = (trim((string) $store->shipstation_api_key) === '') !== (trim((string) $store->shipstation_api_secret) === '');
        $result = null;
        $reportFailed = false;

        if (! $shopifyConfigurationError && ! $shipStationConfigurationWarning) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Refund tracker report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'refund_tracker', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.refund-tracker', $this->viewData($startDate, $endDate, $result, $reportFailed, $shopifyConfigurationError, $shipStationConfigurationWarning));
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?RefundTrackerResult $result = null, bool $reportFailed = false, bool $shopifyConfigurationError = false, bool $shipStationConfigurationWarning = false): array
    {
        return compact('result', 'reportFailed', 'shopifyConfigurationError', 'shipStationConfigurationWarning') + [
            'startDate' => $startDate ?? now()->subDays(30)->toDateString(),
            'endDate' => $endDate ?? now()->toDateString(),
        ];
    }
}
