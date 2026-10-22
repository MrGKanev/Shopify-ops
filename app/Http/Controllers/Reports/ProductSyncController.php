<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunProductSyncReport;
use App\Http\Requests\ProductSyncRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductSyncController extends ReportController
{
    public function create(): View
    {
        return view('reports.product-sync', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ProductSyncRequest $request, RunProductSyncReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials() || $store->missingShipStationCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'product_sync', $report::class, [$request->validated()], null, null, fn (?ReportResult $result, bool $reportFailed) => view('reports.product-sync', ['result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]));
    }
}
