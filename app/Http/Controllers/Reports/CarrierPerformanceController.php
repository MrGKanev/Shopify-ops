<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\CarrierPerformanceResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunCarrierPerformanceReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarrierPerformanceRequest;
use Illuminate\View\View;
use Throwable;

class CarrierPerformanceController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.carrier-performance', $this->viewData());
    }

    public function store(CarrierPerformanceRequest $request, RunCarrierPerformanceReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = $store->missingShipStationCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Carrier performance report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'carrier_performance', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.carrier-performance', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?CarrierPerformanceResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
