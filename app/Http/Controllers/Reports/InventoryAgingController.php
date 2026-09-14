<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\InventoryAgingResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunInventoryAgingReport;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryAgingRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class InventoryAgingController extends Controller
{
    use RecordsReportRun;

    public function create(): View
    {
        return view('reports.inventory-aging', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(InventoryAgingRequest $request, RunInventoryAgingReport $report, RecordRun $runs): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $result = null;
        $reportFailed = false;
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';

        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Inventory aging report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
            $this->recordReportRun($runs, $store, 'inventory_aging', $started, $startDate, $endDate, $result->orders ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.inventory-aging', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof InventoryAgingResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
