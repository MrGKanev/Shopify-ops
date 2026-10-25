<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunShipmentActivityReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShipmentActivityController extends ReportController
{
    use LogsReportFailure;

    public function create(Request $request): View
    {
        $today = now($this->resolveStore($request)->shopTimezone());

        return view('reports.shipment-activity', ['result' => null, 'reportFailed' => false, 'configurationError' => false, 'startDate' => $today->copy()->subDays(30)->toDateString(), 'endDate' => $today->subDay()->toDateString()]);
    }

    public function store(DateRangeReportRequest $request, RunShipmentActivityReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $report->assertPeriod($store, $startDate, $endDate);
        $configurationError = $store->missingShopifyCredentials();
        if ($request->isMethod('GET') && $request->input('report_run') !== null) {
            $result = $reports->completedResult($store, 'shipment_activity', $report::class, [$startDate, $endDate]);
            abort_if($result === null, 404);

            return view('reports.shipment-activity', ['result' => $result, 'reportFailed' => false, 'configurationError' => $configurationError, 'startDate' => $startDate, 'endDate' => $endDate]);
        }

        return $this->runQueued($reports, $request, $store, $configurationError, 'shipment_activity', $report::class, [$startDate, $endDate], $startDate, $endDate, fn (?ReportResult $result, bool $failed): View => view('reports.shipment-activity', ['result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError, 'startDate' => $startDate, 'endDate' => $endDate]));
    }

    public function export(DateRangeReportRequest $request, RunShipmentActivityReport $report, QueuedReportRunner $reports, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $report->assertPeriod($store, $startDate, $endDate);
        $result = $reports->completedResult($store, 'shipment_activity', $report::class, [$startDate, $endDate]);
        if ($result === null && $store->missingShopifyCredentials()) {
            return back()->withErrors(['export' => __('reports.shopify_credentials_incomplete')]);
        }
        try {
            $result ??= $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('Shipment activity CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => __('reports.export_failed')]);
        }
        $rows = array_map(fn (array $row): array => [$row['date'], $row['created_orders'], $row['recorded_carrier_acceptances'], $result->meta['timezone'], $result->meta['shopify_store'], $result->meta['shipstation_store_number'], $result->meta['carrier_coverage'], $result->meta['tracking_observed_as_of'], implode('; ', $result->meta['scopes']), $result->meta['captured_at']], $result->rows);

        return $csv->download("shipment-activity-{$startDate}-to-{$endDate}.csv", ['Date', 'Created orders (Shopify, excluding test)', 'Recorded carrier acceptances (observed labels only)', 'Timezone', 'Shopify store', 'ShipStation store number', 'Carrier coverage', 'Tracking observed as of', 'Shopify access scopes', 'Captured at'], $rows);
    }
}
