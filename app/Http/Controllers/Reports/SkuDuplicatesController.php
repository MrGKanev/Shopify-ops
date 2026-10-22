<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunSkuDuplicatesReport;
use App\Http\Requests\SkuDuplicatesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SkuDuplicatesController extends ReportController
{
    public function create(): View
    {
        return view('reports.sku-duplicates', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(SkuDuplicatesRequest $request, RunSkuDuplicatesReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'sku_duplicates', $report::class, [], null, null, fn (?ReportResult $result, bool $failed) => view('reports.sku-duplicates', ['result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
