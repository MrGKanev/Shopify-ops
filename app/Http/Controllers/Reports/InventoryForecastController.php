<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunInventoryForecastReport;
use App\Http\Requests\InventoryForecastRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InventoryForecastController extends ReportController
{
    public function create(): View
    {
        return view('reports.inventory-forecast', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(InventoryForecastRequest $request, RunInventoryForecastReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $endDate = now()->toDateString();
        $startDate = now()->subDays(30)->toDateString();
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'inventory_forecast', $report::class, [$startDate, $endDate], $startDate, $endDate, fn (?ReportResult $result, bool $failed) => view('reports.inventory-forecast', ['result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
