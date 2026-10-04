<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\InventoryForecastResult;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunInventoryForecastReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryForecastRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InventoryForecastController extends Controller
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
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $run = $reports->run($request, $store, 'inventory_forecast', $report::class, [$startDate, $endDate], $startDate, $endDate, 'orders', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.inventory-forecast', ['result' => $result instanceof InventoryForecastResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
