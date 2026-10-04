<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunTagAuditReport;
use App\Application\Reports\TagAuditResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TagAuditController extends Controller
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
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $run = $reports->run($request, $store, 'tag_audit', $report::class, [$startDate, $endDate, now()->subDays(90)->toDateString()], $startDate, $endDate, 'scanned', 'count:tags');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.tag-audit', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof TagAuditResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
