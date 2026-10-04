<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\DuplicateOrderResult;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunDuplicateOrderReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DuplicateOrderController extends Controller
{
    public function create(): View
    {
        return view('reports.duplicate-orders', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DateRangeReportRequest $request, RunDuplicateOrderReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'duplicate_orders', $report::class, [$startDate, $endDate], $startDate, $endDate, 'scanned', 'count:pairs');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.duplicate-orders', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof DuplicateOrderResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
