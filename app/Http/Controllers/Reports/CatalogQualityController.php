<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunCatalogQualityReport;
use App\Http\Requests\CatalogQualityRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CatalogQualityController extends ReportController
{
    public function create(): View
    {
        return view('reports.catalog-quality', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(CatalogQualityRequest $request, RunCatalogQualityReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'catalog_quality', $report::class, $request->boolean('include_customs') ? [true, $request->validated('after')] : [], null, null, fn (?ReportResult $result, bool $failed) => view('reports.catalog-quality', ['result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
