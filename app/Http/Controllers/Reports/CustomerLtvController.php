<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\CustomerLtvResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunCustomerLtvReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerLtvRequest;
use Illuminate\View\View;
use Throwable;

class CustomerLtvController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.customer-ltv', ['startDate' => now()->subYear()->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(CustomerLtvRequest $request, RunCustomerLtvReport $report, RecordRun $runs): View
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
                $this->logFailure('Customer LTV report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'customer_ltv', $started, $startDate, $endDate, $result->scanned ?? 0, $result->customers ?? 0, $reportFailed);
        }

        return view('reports.customer-ltv', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof CustomerLtvResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
