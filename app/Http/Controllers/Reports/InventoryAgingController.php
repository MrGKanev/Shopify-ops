<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunInventoryAgingReport;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InventoryAgingController extends ReportController
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
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'inventory_aging', $report::class, [$startDate, $endDate], $startDate, $endDate, fn (?ReportResult $result, bool $failed) => view('reports.inventory-aging', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
