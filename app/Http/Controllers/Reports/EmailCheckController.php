<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\EmailCheckResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunEmailCheckReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmailCheckRequest;
use Illuminate\View\View;
use Throwable;

class EmailCheckController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.email-check', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(EmailCheckRequest $request, RunEmailCheckReport $report, RecordRun $runs): View
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
                $this->logFailure('Email check report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'email_check', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.email-check', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof EmailCheckResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
