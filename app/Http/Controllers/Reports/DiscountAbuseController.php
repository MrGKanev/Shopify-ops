<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RunDiscountAbuseReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiscountAbuseRequest;
use Illuminate\View\View;
use Throwable;

class DiscountAbuseController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.discount-abuse', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'minimumEmails' => 3, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DiscountAbuseRequest $request, RunDiscountAbuseReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $minimumEmails = (int) $request->validated('minimum_emails');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate, $minimumEmails);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Discount abuse report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'discount_abuse', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.discount-abuse', ['startDate' => $startDate, 'endDate' => $endDate, 'minimumEmails' => $minimumEmails, 'result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
