<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunVoidedShipmentsReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Requests\DateRangeReportRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class VoidedShipmentsController extends ReportController
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.voided-shipments', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunVoidedShipmentsReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        $configurationError = $this->configurationError($store);

        return $this->runQueued($reports, $request, $store, $configurationError, 'voided_shipments', $report::class, [$startDate, $endDate], $startDate, $endDate, fn (?ReportResult $result, bool $reportFailed) => view('reports.voided-shipments', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError)));
    }

    public function export(DateRangeReportRequest $request, RunVoidedShipmentsReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => __('reports.shipstation_credentials_incomplete')]);
        }
        try {
            $result = $reports->completedResult($store, 'voided_shipments', $report::class, [$startDate, $endDate]) ?? $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('Voided shipments CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => __('reports.export_failed')]);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['shipment_id'], $row['void_date'], $row['ship_date'], $row['carrier'], $row['service'], $row['tracking'], $row['ship_to_name'], $row['ship_to_city'], $row['ship_to_state'], $row['ship_to_zip'], $row['ship_to_country'], $row['refund_billing']['currency'] ?? null, $row['refund_billing']['expected_refund'] ?? null, $row['refund_billing']['refunds'] ?? null, $row['refund_billing']['outstanding_refund'] ?? null, $row['refund_billing']['refund_status'] ?? 'unconfirmed'], $result->rows);

        return $csv->download("voided-shipments-{$startDate}-to-{$endDate}.csv", ['Order', 'Shipment ID', 'Void date', 'Ship date', 'Carrier', 'Service', 'Tracking', 'Ship-to name', 'City', 'State', 'Postal code', 'Country', 'Billing currency', 'Expected refund', 'Recorded label refunds', 'Outstanding refund', 'Refund status'], $rows);
    }

    /** @return array{Store, string, string} */
    private function context(DateRangeReportRequest $request): array
    {

        return [$this->resolveStore($request), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShipStationCredentials();
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
