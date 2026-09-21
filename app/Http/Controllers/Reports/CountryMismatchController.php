<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RunCountryMismatchReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\CountryMismatchRequest;
use Illuminate\View\View;
use Throwable;

class CountryMismatchController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.country-mismatch', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false]);
    }

    public function store(CountryMismatchRequest $request, RunCountryMismatchReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $result = null;
        $reportFailed = false;
        $started = microtime(true);
        try {
            $result = $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $reportFailed = true;
            $this->logFailure('Country mismatch report failed.', $exception, $store);
        }
        $this->recordReportRun($runs, $store, 'country_mismatch', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);

        return view('reports.country-mismatch', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed]);
    }
}
