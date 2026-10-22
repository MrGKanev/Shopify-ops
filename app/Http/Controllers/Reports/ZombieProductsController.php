<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunZombieProductsReport;
use App\Http\Requests\ZombieProductsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ZombieProductsController extends ReportController
{
    public function create(): View
    {
        return view('reports.zombie-products', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ZombieProductsRequest $request, RunZombieProductsReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'zombie_products', $report::class, [], null, null, fn (?ReportResult $result, bool $failed) => view('reports.zombie-products', ['result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
