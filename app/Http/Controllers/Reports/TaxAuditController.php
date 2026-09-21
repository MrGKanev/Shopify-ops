<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RunTaxAuditReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaxAuditRequest;
use Illuminate\View\View;
use Throwable;

class TaxAuditController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.tax-audit', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'minimum' => 5, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(TaxAuditRequest $request, RunTaxAuditReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $data = $request->validated();
        $result = null;
        $failed = false;
        $configurationError = $store->missingShopifyCredentials();
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, (string) $data['start_date'], (string) $data['end_date'], (float) $data['minimum']);
            } catch (Throwable $exception) {
                $failed = true;
                $this->logFailure('Tax audit report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'tax_audit', $started, (string) $data['start_date'], (string) $data['end_date'], $result->scanned ?? 0, count($result->rows ?? []), $failed);
        }

        return view('reports.tax-audit', ['startDate' => $data['start_date'], 'endDate' => $data['end_date'], 'minimum' => $data['minimum'], 'result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]);
    }
}
