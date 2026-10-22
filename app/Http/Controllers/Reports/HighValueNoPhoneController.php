<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunHighValueNoPhoneReport;
use App\Http\Requests\HighValueNoPhoneRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HighValueNoPhoneController extends ReportController
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

        return $this->runQueued($reports, $request, $store, false, 'high_value_no_phone', $report::class, [$startDate, $endDate, $minimum, $currency], $startDate, $endDate, fn (?ReportResult $result, bool $failed) => view('reports.high-value-no-phone', ['startDate' => $startDate, 'endDate' => $endDate, 'minimum' => $minimum, 'currency' => $currency, 'result' => $result, 'reportFailed' => $failed]));
    }
}
