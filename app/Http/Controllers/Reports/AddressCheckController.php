<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\AddressCheckResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunAddressCheckReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressCheckRequest;
use Illuminate\View\View;
use Throwable;

class AddressCheckController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.address-check', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'poBoxOnly' => false, 'unfulfilledOnly' => false, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(AddressCheckRequest $request, RunAddressCheckReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $poBoxOnly = $request->boolean('po_box_only');
        $unfulfilledOnly = $request->boolean('unfulfilled_only');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate, $poBoxOnly, $unfulfilledOnly);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Address check report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'address_check', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.address-check', ['startDate' => $startDate, 'endDate' => $endDate, 'poBoxOnly' => $poBoxOnly, 'unfulfilledOnly' => $unfulfilledOnly, 'result' => $result instanceof AddressCheckResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
