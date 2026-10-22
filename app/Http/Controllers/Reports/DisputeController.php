<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunDisputeReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DisputeController extends ReportController
{
    public function create(): View
    {
        return view('reports.disputes', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(Request $request, RunDisputeReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        abort_unless($request->user()?->can('run-audits'), 403);
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'disputes', $report::class, [now()->getTimestamp()], null, null, fn (?ReportResult $result, bool $reportFailed) => view('reports.disputes', ['result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]));
    }
}
