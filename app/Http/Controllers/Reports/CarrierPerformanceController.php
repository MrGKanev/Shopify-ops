<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunCarrierPerformanceReport;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CarrierPerformanceController extends ReportController
{
    public function create(): View
    {
        return view('reports.carrier-performance', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunCarrierPerformanceReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = $store->missingShipStationCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'carrier_performance', $report::class, [$startDate, $endDate], $startDate, $endDate, fn (?ReportResult $result, bool $reportFailed) => view('reports.carrier-performance', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError)));
    }

    /**
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @return array<string, mixed>
     */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?ReportResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
