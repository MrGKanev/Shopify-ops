<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunDisputeReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DisputeController extends Controller
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
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'disputes', $report::class, [now()->getTimestamp()], null, null, 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.disputes', ['result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
