<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunOrderEditReport;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OrderEditController extends ReportController
{
    public function create(): View
    {
        return view('reports.order-edits', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'configurationError' => false, 'reportFailed' => false]);
    }

    public function store(DateRangeReportRequest $request, RunOrderEditReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'order_edits', $report::class, [$start, $end], $start, $end, fn (?ReportResult $result, bool $reportFailed) => view('reports.order-edits', ['startDate' => $start, 'endDate' => $end, 'result' => $result instanceof ReportResult ? $result : null, 'configurationError' => $configurationError, 'reportFailed' => $reportFailed]));
    }
}
