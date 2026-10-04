<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\InventoryAgingResult;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunInventoryAgingReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InventoryAgingController extends Controller
{
    public function create(): View
    {
        return view('reports.inventory-aging', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DateRangeReportRequest $request, RunInventoryAgingReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $run = $reports->run($request, $store, 'inventory_aging', $report::class, [$startDate, $endDate], $startDate, $endDate, 'orders', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.inventory-aging', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof InventoryAgingResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
