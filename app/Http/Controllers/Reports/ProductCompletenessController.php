<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunProductCompletenessReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductCompletenessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductCompletenessController extends Controller
{
    public function create(): View
    {
        return view('reports.product-completeness', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ProductCompletenessRequest $request, RunProductCompletenessReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $run = $reports->run($request, $store, 'product_completeness', $report::class, [], null, null);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.product-completeness', ['result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
