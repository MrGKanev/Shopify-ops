<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\PartialFulfillmentResult;
use App\Application\Reports\RunPartialFulfillmentReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\PartialFulfillmentRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PartialFulfillmentController extends Controller
{
    public function create(): View
    {
        return view('reports.partial-fulfillment', $this->viewData());
    }

    public function store(PartialFulfillmentRequest $request, RunPartialFulfillmentReport $report): View
    {
        [$store, $startDate, $endDate, $threshold] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate, $threshold);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Partial fulfillment report failed.', $exception, $store);
            }
        }

        return view('reports.partial-fulfillment', $this->viewData($startDate, $endDate, $threshold, $result, $reportFailed, $configurationError));
    }

    public function export(PartialFulfillmentRequest $request, RunPartialFulfillmentReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate, $threshold] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        try {
            $result = $report->handle($store, $startDate, $endDate, $threshold);
        } catch (Throwable $exception) {
            $this->logFailure('Partial fulfillment CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['last_fulfilled'], $row['days_stalled'], implode('; ', array_map(fn (array $item): string => $item['name'].($item['sku'] ? ' ['.$item['sku'].']' : '').' ×'.$item['qty'], $row['unfulfilled_items'])), $row['email'], $row['total_price'], $row['financial']], $result->rows);

        return $csv->download("partial-fulfillment-stalls-{$startDate}-to-{$endDate}.csv", ['Order', 'Placed', 'Last fulfillment', 'Days stalled', 'Unfulfilled items', 'Email', 'Total', 'Financial status'], $rows);
    }

    /** @return array{Store, string, string, int} */
    private function context(PartialFulfillmentRequest $request): array
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return [$request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail(), (string) $request->validated('start_date'), (string) $request->validated('end_date'), (int) $request->validated('threshold')];
    }

    private function configurationError(Store $store): bool
    {
        return trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
    }

    private function logFailure(string $message, Throwable $exception, Store $store): void
    {
        Log::warning($message, ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, int $threshold = 7, ?PartialFulfillmentResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('threshold', 'result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(90)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
