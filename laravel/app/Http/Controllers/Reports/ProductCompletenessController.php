<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\ProductCompletenessResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunProductCompletenessReport;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductCompletenessRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ProductCompletenessController extends Controller
{
    use RecordsReportRun;

    public function create(): View
    {
        return view('reports.product-completeness', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ProductCompletenessRequest $request, RunProductCompletenessReport $report, RecordRun $runs): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $result = null;
        $reportFailed = false;
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';

        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Product completeness report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
            $this->recordReportRun($runs, $store, 'product_completeness', $started, null, null, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.product-completeness', ['result' => $result instanceof ProductCompletenessResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
