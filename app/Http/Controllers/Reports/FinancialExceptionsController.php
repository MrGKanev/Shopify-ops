<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunFinancialExceptionsReport;
use App\Http\Requests\FinancialExceptionsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FinancialExceptionsController extends ReportController
{
    public function create(): View
    {
        return view('reports.financial-exceptions', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(FinancialExceptionsRequest $request, RunFinancialExceptionsReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'financial_exceptions', $report::class, [$request->validated()], null, null, fn (?ReportResult $result, bool $reportFailed) => view('reports.financial-exceptions', ['result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]));
    }
}
