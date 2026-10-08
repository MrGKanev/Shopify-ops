<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunProductSyncReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductSyncRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductSyncController extends Controller
{
    public function create(): View
    {
        return view('reports.product-sync', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ProductSyncRequest $request, RunProductSyncReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials() || $store->missingShipStationCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'product_sync', $report::class, [$request->validated()], null, null);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.product-sync', ['result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
