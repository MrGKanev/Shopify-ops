<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunAddressCheckReport;
use App\Http\Requests\AddressCheckRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AddressCheckController extends ReportController
{
    public function create(): View
    {
        return view('reports.address-check', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'poBoxOnly' => false, 'unfulfilledOnly' => false, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(AddressCheckRequest $request, RunAddressCheckReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $poBoxOnly = $request->boolean('po_box_only');
        $unfulfilledOnly = $request->boolean('unfulfilled_only');
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'address_check', $report::class, [$startDate, $endDate, $poBoxOnly, $unfulfilledOnly], $startDate, $endDate, fn (?ReportResult $result, bool $reportFailed) => view('reports.address-check', ['startDate' => $startDate, 'endDate' => $endDate, 'poBoxOnly' => $poBoxOnly, 'unfulfilledOnly' => $unfulfilledOnly, 'result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]));
    }
}
