<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\ItemMismatchResult;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunItemMismatchReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ItemMismatchController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.item-mismatch', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunItemMismatchReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'item_mismatch', $report::class, [$start, $end], $start, $end, 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.item-mismatch', $this->viewData($start, $end, $result, $reportFailed, $configurationError));
    }

    public function export(DateRangeReportRequest $request, RunItemMismatchReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify and ShipStation credentials are required.']);
        }try {
            $result = $reports->completedResult($store, 'item_mismatch', $report::class, [$start, $end]) ?? $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            $this->logFailure('Item mismatch CSV failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $format = fn (array $items): string => implode('; ', array_map(fn (string $sku, int $qty): string => $sku.' ×'.$qty, array_keys($items), $items));
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['email'], $row['order_type'], $format($row['missing']), $format($row['extra']), implode('; ', $row['missing_required']), $row['total']], $result->rows);

        return $csv->download("item-mismatch-{$start}-to-{$end}.csv", ['Order', 'Date', 'Email', 'Order type', 'Missing', 'Extra', 'Missing required', 'Total'], $rows);
    }

    /** @return array{Store,string,string} */
    private function context(DateRangeReportRequest $request): array
    {

        return [$this->resolveStore($request), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShopifyCredentials() || $store->missingShipStationCredentials();
    }

    /** @return array<string,mixed> */
    private function viewData(?string $start = null, ?string $end = null, ?ItemMismatchResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
