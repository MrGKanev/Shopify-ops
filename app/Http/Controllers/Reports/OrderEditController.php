<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\OrderEditResult;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunOrderEditReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OrderEditController extends Controller
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
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'order_edits', $report::class, [$start, $end], $start, $end, 'count:rows', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.order-edits', ['startDate' => $start, 'endDate' => $end, 'result' => $result instanceof OrderEditResult ? $result : null, 'configurationError' => $configurationError, 'reportFailed' => $reportFailed]);
    }
}
