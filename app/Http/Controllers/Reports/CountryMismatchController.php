<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunCountryMismatchReport;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CountryMismatchController extends ReportController
{
    public function create(): View
    {
        return view('reports.country-mismatch', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DateRangeReportRequest $request, RunCountryMismatchReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'country_mismatch', $report::class, [$startDate, $endDate], $startDate, $endDate, fn (?ReportResult $result, bool $reportFailed) => view('reports.country-mismatch', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]));
    }
}
