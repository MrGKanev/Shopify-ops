<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunCatalogQualityReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogQualityRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CatalogQualityController extends Controller
{
    public function create(): View
    {
        return view('reports.catalog-quality', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(CatalogQualityRequest $request, RunCatalogQualityReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $run = $reports->run($request, $store, 'catalog_quality', $report::class, [], null, null, 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.catalog-quality', ['result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
