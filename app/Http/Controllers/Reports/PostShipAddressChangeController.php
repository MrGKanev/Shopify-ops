<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\PostShipAddressChangeResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunPostShipAddressChangeReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressChangeRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PostShipAddressChangeController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.post-ship-address-changes', $this->viewData());
    }

    public function store(AddressChangeRequest $request, RunPostShipAddressChangeReport $report, RecordRun $runs): View
    {
        [$store, $startDate, $endDate] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Post-ship address change report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'post_ship_address_changes', $started, $startDate, $endDate, count($result->rows ?? []), count($result->rows ?? []), $reportFailed);
        }

        return view('reports.post-ship-address-changes', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    public function export(AddressChangeRequest $request, RunPostShipAddressChangeReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        try {
            $result = $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('Post-ship address change CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['fulfillment_at'], $row['changed_at'], $row['mins_after_ship'], $row['email'], $row['addr_name'], $row['addr_line'], number_format((float) $row['total'], 2, '.', ''), $row['financial'], $row['fulfillment']], $result->rows);

        return $csv->download("post-ship-address-changes-{$startDate}-to-{$endDate}.csv", ['Order', 'Placed', 'First fulfillment', 'Changed', 'Minutes after shipment', 'Email', 'Address name', 'Current shipping address', 'Total', 'Financial status', 'Fulfillment status'], $rows);
    }

    /** @return array{Store, string, string} */
    private function context(AddressChangeRequest $request): array
    {

        return [$this->resolveStore($request), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShopifyCredentials();
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?PostShipAddressChangeResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
