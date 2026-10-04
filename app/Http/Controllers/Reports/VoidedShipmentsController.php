<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunVoidedShipmentsReport;
use App\Application\Reports\VoidedShipmentsResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class VoidedShipmentsController extends Controller
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
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'voided_shipments', $report::class, [$startDate, $endDate], $startDate, $endDate, 'count:rows', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.voided-shipments', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    public function export(DateRangeReportRequest $request, RunVoidedShipmentsReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'ShipStation credentials are incomplete for the active store.']);
        }
        try {
            $result = $reports->completedResult($store, 'voided_shipments', $report::class, [$startDate, $endDate]) ?? $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('Voided shipments CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['shipment_id'], $row['void_date'], $row['ship_date'], $row['carrier'], $row['service'], $row['tracking'], $row['ship_to_name'], $row['ship_to_city'], $row['ship_to_state'], $row['ship_to_zip'], $row['ship_to_country']], $result->rows);

        return $csv->download("voided-shipments-{$startDate}-to-{$endDate}.csv", ['Order', 'Shipment ID', 'Void date', 'Ship date', 'Carrier', 'Service', 'Tracking', 'Ship-to name', 'City', 'State', 'Postal code', 'Country'], $rows);
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

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?VoidedShipmentsResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
