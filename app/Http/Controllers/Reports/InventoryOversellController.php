<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\InventoryOversellResult;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunInventoryOversellReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryOversellRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InventoryOversellController extends Controller
{
    public function create(): View
    {
        return view('reports.inventory-oversell', ['result' => null, 'reportFailed' => false, 'shopifyConfigurationError' => false, 'shipStationConfigurationError' => false]);
    }

    public function store(InventoryOversellRequest $request, RunInventoryOversellReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $result = null;
        $reportFailed = false;
        $shopifyConfigurationError = $store->missingShopifyCredentials();
        $shipStationConfigurationError = $store->missingShipStationCredentials();

        if (! $shopifyConfigurationError && ! $shipStationConfigurationError) {
            $run = $reports->run($request, $store, 'inventory_oversell', $report::class, [], null, null, 'products', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.inventory-oversell', [
            'result' => $result instanceof InventoryOversellResult ? $result : null,
            'reportFailed' => $reportFailed,
            'shopifyConfigurationError' => $shopifyConfigurationError,
            'shipStationConfigurationError' => $shipStationConfigurationError,
        ]);
    }
}
