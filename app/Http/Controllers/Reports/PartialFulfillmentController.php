<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunPartialFulfillmentReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\PartialFulfillmentRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PartialFulfillmentController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.partial-fulfillment', $this->viewData());
    }

    public function store(PartialFulfillmentRequest $request, RunPartialFulfillmentReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store, $startDate, $endDate, $threshold] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'partial_fulfillment', $report::class, [$startDate, $endDate, $threshold], $startDate, $endDate);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.partial-fulfillment', $this->viewData($startDate, $endDate, $threshold, $result, $reportFailed, $configurationError));
    }

    public function export(PartialFulfillmentRequest $request, RunPartialFulfillmentReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate, $threshold] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => __('reports.shopify_credentials_incomplete')]);
        }
        try {
            $result = $reports->completedResult($store, 'partial_fulfillment', $report::class, [$startDate, $endDate, $threshold]) ?? $report->handle($store, $startDate, $endDate, $threshold);
        } catch (Throwable $exception) {
            $this->logFailure('Partial fulfillment CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => __('reports.export_failed')]);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['last_fulfilled'], $row['days_stalled'], implode('; ', array_map(fn (array $item): string => $item['name'].($item['sku'] ? ' ['.$item['sku'].']' : '').' ×'.$item['qty'], $row['unfulfilled_items'])), $row['email'], $row['total_price'], $row['financial']], $result->rows);

        return $csv->download("partial-fulfillment-stalls-{$startDate}-to-{$endDate}.csv", ['Order', 'Placed', 'Last fulfillment', 'Days stalled', 'Unfulfilled items', 'Email', 'Total', 'Financial status'], $rows);
    }

    /** @return array{Store, string, string, int} */
    private function context(PartialFulfillmentRequest $request): array
    {

        return [$this->resolveStore($request), (string) $request->validated('start_date'), (string) $request->validated('end_date'), (int) $request->validated('threshold')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShopifyCredentials();
    }

    /**
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @return array<string, mixed>
     */
    private function viewData(?string $startDate = null, ?string $endDate = null, int $threshold = 7, ?ReportResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('threshold', 'result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(90)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
