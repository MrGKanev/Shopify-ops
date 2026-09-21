<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RunRepeatRefundReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\RepeatRefundRequest;
use Illuminate\View\View;
use Throwable;

class RepeatRefundController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.repeat-refunds', ['startDate' => now()->subDays(90)->toDateString(), 'endDate' => now()->toDateString(), 'minimum' => 2, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(RepeatRefundRequest $request, RunRepeatRefundReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $minimum = (int) $request->validated('minimum');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $start, $end, $minimum);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Repeat refund report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'repeat_refunds', $started, $start, $end, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.repeat-refunds', ['startDate' => $start, 'endDate' => $end, 'minimum' => $minimum, 'result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
