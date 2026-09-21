<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\OrderEditResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunOrderEditReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderEditRequest;
use Illuminate\View\View;
use Throwable;

class OrderEditController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.order-edits', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'configurationError' => false, 'reportFailed' => false]);
    }

    public function store(OrderEditRequest $request, RunOrderEditReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $start, $end);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Order edit report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'order_edits', $started, $start, $end, count($result->rows ?? []), count($result->rows ?? []), $reportFailed);
        }

        return view('reports.order-edits', ['startDate' => $start, 'endDate' => $end, 'result' => $result instanceof OrderEditResult ? $result : null, 'configurationError' => $configurationError, 'reportFailed' => $reportFailed]);
    }
}
