<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunTagAuditReport;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TagAuditController extends ReportController
{
    public function create(): View
    {
        return view('reports.tag-audit', ['startDate' => now()->subDays(90)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DateRangeReportRequest $request, RunTagAuditReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'tag_audit', $report::class, [$startDate, $endDate, now()->subDays(90)->toDateString()], $startDate, $endDate, fn (?ReportResult $result, bool $failed) => view('reports.tag-audit', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
