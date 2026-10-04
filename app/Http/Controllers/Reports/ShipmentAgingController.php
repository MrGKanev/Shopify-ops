<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunShipmentAgingReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShipmentAgingRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShipmentAgingController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.shipment-aging', $this->viewData());
    }

    public function store(ShipmentAgingRequest $request, RunShipmentAgingReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store,$threshold] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'shipment_aging', $report::class, [$threshold], null, null);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.shipment-aging', $this->viewData($threshold, $result, $reportFailed, $configurationError));
    }

    public function export(ShipmentAgingRequest $request, RunShipmentAgingReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        [$store,$threshold] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => __('reports.shipstation_credentials_incomplete')]);
        }
        try {
            $result = $reports->completedResult($store, 'shipment_aging', $report::class, [$threshold]) ?? $report->handle($store, $threshold);
        } catch (Throwable $exception) {
            $this->logFailure('Shipment aging CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => __('reports.export_failed')]);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['order_date'], $row['days'], $row['customer'], $row['email'], $row['total'], $row['order_type'], implode('; ', array_map(fn (int|string $sku, mixed $qty): string => $sku.' ×'.$qty, array_keys($row['skus']), $row['skus']))], $result->rows);

        return $csv->download('shipment-aging-'.now()->toDateString().'.csv', ['Order', 'Date', 'Days', 'Customer', 'Email', 'Total', 'Type', 'SKUs'], $rows);
    }

    /** @return array{Store,int} */
    private function context(ShipmentAgingRequest $request): array
    {

        return [$this->resolveStore($request), (int) $request->validated('threshold')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShipStationCredentials();
    }

    /**
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @return array<string,mixed>
     */
    private function viewData(int $threshold = 3, ?ReportResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('threshold', 'result', 'reportFailed', 'configurationError');
    }
}
