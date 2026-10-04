<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunAddressCheckReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressCheckRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AddressCheckController extends Controller
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
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'address_check', $report::class, [$startDate, $endDate, $poBoxOnly, $unfulfilledOnly], $startDate, $endDate);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.address-check', ['startDate' => $startDate, 'endDate' => $endDate, 'poBoxOnly' => $poBoxOnly, 'unfulfilledOnly' => $unfulfilledOnly, 'result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
