<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunDiscountAbuseReport;
use App\Http\Requests\DiscountAbuseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DiscountAbuseController extends ReportController
{
    public function create(): View
    {
        return view('reports.discount-abuse', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'minimumEmails' => 3, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DiscountAbuseRequest $request, RunDiscountAbuseReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $minimumEmails = (int) $request->validated('minimum_emails');
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'discount_abuse', $report::class, [$startDate, $endDate, $minimumEmails], $startDate, $endDate, fn (?ReportResult $result, bool $reportFailed) => view('reports.discount-abuse', ['startDate' => $startDate, 'endDate' => $endDate, 'minimumEmails' => $minimumEmails, 'result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]));
    }
}
