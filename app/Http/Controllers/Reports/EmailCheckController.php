<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunEmailCheckReport;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EmailCheckController extends ReportController
{
    public function create(): View
    {
        return view('reports.email-check', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DateRangeReportRequest $request, RunEmailCheckReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'email_check', $report::class, [$startDate, $endDate], $startDate, $endDate, fn (?ReportResult $result, bool $failed) => view('reports.email-check', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
