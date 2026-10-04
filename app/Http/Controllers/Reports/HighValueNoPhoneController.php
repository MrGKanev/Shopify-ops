<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunHighValueNoPhoneReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\HighValueNoPhoneRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HighValueNoPhoneController extends Controller
{
    public function create(): View
    {
        return view('reports.high-value-no-phone', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'minimum' => 200, 'currency' => 'USD', 'result' => null, 'reportFailed' => false]);
    }

    public function store(HighValueNoPhoneRequest $request, RunHighValueNoPhoneReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $minimum = (float) $request->validated('minimum');
        $currency = $request->validated('currency') === null ? null : (string) $request->validated('currency');
        $result = null;
        $reportFailed = false;

        $run = $reports->run($request, $store, 'high_value_no_phone', $report::class, [$startDate, $endDate, $minimum, $currency], $startDate, $endDate);
        if ($reports->shouldRedirect($request, $run)) {
            return $reports->redirectToResult($request);
        }
        $result = $run->result();
        $reportFailed = $run->hasFailed();

        return view('reports.high-value-no-phone', ['startDate' => $startDate, 'endDate' => $endDate, 'minimum' => $minimum, 'currency' => $currency, 'result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed]);
    }
}
