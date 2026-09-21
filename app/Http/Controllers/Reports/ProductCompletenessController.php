<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\ProductCompletenessResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunProductCompletenessReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductCompletenessRequest;
use Illuminate\View\View;
use Throwable;

class ProductCompletenessController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.product-completeness', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ProductCompletenessRequest $request, RunProductCompletenessReport $report, RecordRun $runs): View
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
                $this->logFailure('Product completeness report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'product_completeness', $started, null, null, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.product-completeness', ['result' => $result instanceof ProductCompletenessResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
