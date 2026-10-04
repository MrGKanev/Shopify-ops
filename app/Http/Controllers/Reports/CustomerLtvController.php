<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\CustomerLtvResult;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunCustomerLtvReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CustomerLtvController extends Controller
{
    public function create(): View
    {
        return view('reports.customer-ltv', ['startDate' => now()->subYear()->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DateRangeReportRequest $request, RunCustomerLtvReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'customer_ltv', $report::class, [$startDate, $endDate], $startDate, $endDate, 'scanned', 'customers');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.customer-ltv', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof CustomerLtvResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
