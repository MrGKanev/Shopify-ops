<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\InventoryForecastResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunInventoryForecastReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryForecastRequest;
use Illuminate\View\View;
use Throwable;

class InventoryForecastController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.inventory-forecast', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(InventoryForecastRequest $request, RunInventoryForecastReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $endDate = now()->toDateString();
        $startDate = now()->subDays(30)->toDateString();
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Inventory forecast report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'inventory_forecast', $started, $startDate, $endDate, $result->orders ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.inventory-forecast', ['result' => $result instanceof InventoryForecastResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
