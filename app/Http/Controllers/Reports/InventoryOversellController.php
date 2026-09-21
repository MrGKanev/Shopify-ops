<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\InventoryOversellResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunInventoryOversellReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryOversellRequest;
use Illuminate\View\View;
use Throwable;

class InventoryOversellController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.inventory-oversell', ['result' => null, 'reportFailed' => false, 'shopifyConfigurationError' => false, 'shipStationConfigurationError' => false]);
    }

    public function store(InventoryOversellRequest $request, RunInventoryOversellReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $result = null;
        $reportFailed = false;
        $shopifyConfigurationError = $store->missingShopifyCredentials();
        $shipStationConfigurationError = $store->missingShipStationCredentials();

        if (! $shopifyConfigurationError && ! $shipStationConfigurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Inventory oversell report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'inventory_oversell', $started, null, null, $result->products ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.inventory-oversell', [
            'result' => $result instanceof InventoryOversellResult ? $result : null,
            'reportFailed' => $reportFailed,
            'shopifyConfigurationError' => $shopifyConfigurationError,
            'shipStationConfigurationError' => $shipStationConfigurationError,
        ]);
    }
}
