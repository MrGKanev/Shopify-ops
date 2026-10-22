<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunProductCompletenessReport;
use App\Http\Requests\ProductCompletenessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductCompletenessController extends ReportController
{
    public function create(): View
    {
        return view('reports.product-completeness', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ProductCompletenessRequest $request, RunProductCompletenessReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'product_completeness', $report::class, [], null, null, fn (?ReportResult $result, bool $failed) => view('reports.product-completeness', ['result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
