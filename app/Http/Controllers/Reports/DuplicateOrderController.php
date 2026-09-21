<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\DuplicateOrderResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunDuplicateOrderReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\DuplicateOrderRequest;
use Illuminate\View\View;
use Throwable;

class DuplicateOrderController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.duplicate-orders', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DuplicateOrderRequest $request, RunDuplicateOrderReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Duplicate order report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'duplicate_orders', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->pairs ?? []), $reportFailed);
        }

        return view('reports.duplicate-orders', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof DuplicateOrderResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
