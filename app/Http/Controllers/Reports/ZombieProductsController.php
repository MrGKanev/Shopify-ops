<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RunZombieProductsReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\ZombieProductsRequest;
use Illuminate\View\View;
use Throwable;

class ZombieProductsController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.zombie-products', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ZombieProductsRequest $request, RunZombieProductsReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Zombie products report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'zombie_products', $started, null, null, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.zombie-products', ['result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
