<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\AddressCheckResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunAddressCheckReport;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressCheckRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class AddressCheckController extends Controller
{
    use RecordsReportRun;

    public function create(): View
    {
        return view('reports.address-check', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'poBoxOnly' => false, 'unfulfilledOnly' => false, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(AddressCheckRequest $request, RunAddressCheckReport $report, RecordRun $runs): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $poBoxOnly = $request->boolean('po_box_only');
        $unfulfilledOnly = $request->boolean('unfulfilled_only');
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate, $poBoxOnly, $unfulfilledOnly);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Address check report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
            $this->recordReportRun($runs, $store, 'address_check', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.address-check', ['startDate' => $startDate, 'endDate' => $endDate, 'poBoxOnly' => $poBoxOnly, 'unfulfilledOnly' => $unfulfilledOnly, 'result' => $result instanceof AddressCheckResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
