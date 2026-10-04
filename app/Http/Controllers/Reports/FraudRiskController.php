<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunFraudRiskReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FraudRiskController extends Controller
{
    public function create(): View
    {
        return view('reports.fraud-risk', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DateRangeReportRequest $request, RunFraudRiskReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;

        if (! $configurationError) {
            $run = $reports->run($request, $store, 'fraud_risk', $report::class, [$startDate, $endDate], $startDate, $endDate, 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.fraud-risk', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
