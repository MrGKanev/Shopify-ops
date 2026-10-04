<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunRepeatRefundReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\RepeatRefundRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RepeatRefundController extends Controller
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
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'repeat_refunds', $report::class, [$start, $end, $minimum], $start, $end, 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.repeat-refunds', ['startDate' => $start, 'endDate' => $end, 'minimum' => $minimum, 'result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
