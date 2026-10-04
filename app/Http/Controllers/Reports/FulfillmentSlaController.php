<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunFulfillmentSlaReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\FulfillmentSlaRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FulfillmentSlaController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.fulfillment-sla', $this->viewData());
    }

    public function store(FulfillmentSlaRequest $request, RunFulfillmentSlaReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store, $startDate, $endDate, $threshold] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'fulfillment_sla', $report::class, [$startDate, $endDate, $threshold], $startDate, $endDate, 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.fulfillment-sla', $this->viewData($startDate, $endDate, $threshold, $result, $reportFailed, $configurationError));
    }

    public function export(FulfillmentSlaRequest $request, RunFulfillmentSlaReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate, $threshold] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        try {
            $result = $reports->completedResult($store, 'fulfillment_sla', $report::class, [$startDate, $endDate, $threshold]) ?? $report->handle($store, $startDate, $endDate, $threshold);
        } catch (Throwable $exception) {
            $this->logFailure('Fulfillment SLA CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['fulfilled_at'], $row['days'], $row['method'], $row['region'], $row['order_type'], $row['email'], $row['total'], $row['financial'], $row['fulfillment']], $result->rows);

        return $csv->download("fulfillment-sla-{$startDate}-to-{$endDate}.csv", ['Order', 'Placed', 'First fulfillment', 'Days', 'Method', 'Region', 'Order type', 'Email', 'Total', 'Financial status', 'Fulfillment status'], $rows);
    }

    /** @return array{Store, string, string, int} */
    private function context(FulfillmentSlaRequest $request): array
    {

        return [$this->resolveStore($request), (string) $request->validated('start_date'), (string) $request->validated('end_date'), (int) $request->validated('threshold')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShopifyCredentials();
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, int $threshold = 3, ?ScanResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('threshold', 'result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
