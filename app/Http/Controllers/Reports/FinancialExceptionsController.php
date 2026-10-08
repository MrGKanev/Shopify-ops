<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunFinancialExceptionsReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialExceptionsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FinancialExceptionsController extends Controller
{
    public function create(): View
    {
        return view('reports.financial-exceptions', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(FinancialExceptionsRequest $request, RunFinancialExceptionsReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'financial_exceptions', $report::class, [$request->validated()], null, null);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.financial-exceptions', ['result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
