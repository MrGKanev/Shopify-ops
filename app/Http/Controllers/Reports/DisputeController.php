<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RunDisputeReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DisputeController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.disputes', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(Request $request, RunDisputeReport $report, RecordRun $runs): View
    {
        abort_unless($request->user()?->can('run-audits'), 403);
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, now()->getTimestamp());
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Dispute report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'disputes', $started, null, null, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.disputes', ['result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
