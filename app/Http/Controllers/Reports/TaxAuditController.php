<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunTaxAuditReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaxAuditRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TaxAuditController extends Controller
{
    public function create(): View
    {
        return view('reports.tax-audit', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'minimum' => 5, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(TaxAuditRequest $request, RunTaxAuditReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $data = $request->validated();
        $result = null;
        $failed = false;
        $configurationError = $store->missingShopifyCredentials();
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'tax_audit', $report::class, [(string) $data['start_date'], (string) $data['end_date'], (float) $data['minimum']], (string) $data['start_date'], (string) $data['end_date'], 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $failed = $run->hasFailed();
        }

        return view('reports.tax-audit', ['startDate' => $data['start_date'], 'endDate' => $data['end_date'], 'minimum' => $data['minimum'], 'result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]);
    }
}
