<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunInventoryOversellReport;
use App\Http\Requests\InventoryOversellRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InventoryOversellController extends ReportController
{
    public function create(): View
    {
        return view('reports.inventory-oversell', ['result' => null, 'reportFailed' => false, 'shopifyConfigurationError' => false, 'shipStationConfigurationError' => false]);
    }

    public function store(InventoryOversellRequest $request, RunInventoryOversellReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $shopifyConfigurationError = $store->missingShopifyCredentials();
        $shipStationConfigurationError = $store->missingShipStationCredentials();

        return $this->runQueued($reports, $request, $store, $shopifyConfigurationError || $shipStationConfigurationError, 'inventory_oversell', $report::class, [], null, null, fn (?ReportResult $result, bool $failed) => view('reports.inventory-oversell', [
            'result' => $result,
            'reportFailed' => $failed,
            'shopifyConfigurationError' => $shopifyConfigurationError,
            'shipStationConfigurationError' => $shipStationConfigurationError,
        ]));
    }
}
