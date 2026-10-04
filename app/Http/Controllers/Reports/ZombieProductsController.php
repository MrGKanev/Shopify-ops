<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunZombieProductsReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ZombieProductsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ZombieProductsController extends Controller
{
    public function create(): View
    {
        return view('reports.zombie-products', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ZombieProductsRequest $request, RunZombieProductsReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $run = $reports->run($request, $store, 'zombie_products', $report::class, [], null, null);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.zombie-products', ['result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
