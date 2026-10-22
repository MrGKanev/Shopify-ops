<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunRepeatRefundReport;
use App\Http\Requests\RepeatRefundRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RepeatRefundController extends ReportController
{
    public function create(): View
    {
        return view('reports.repeat-refunds', ['startDate' => now()->subDays(90)->toDateString(), 'endDate' => now()->toDateString(), 'minimum' => 2, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(RepeatRefundRequest $request, RunRepeatRefundReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $minimum = (int) $request->validated('minimum');
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'repeat_refunds', $report::class, [$start, $end, $minimum], $start, $end, fn (?ReportResult $result, bool $reportFailed) => view('reports.repeat-refunds', ['startDate' => $start, 'endDate' => $end, 'minimum' => $minimum, 'result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]));
    }
}
