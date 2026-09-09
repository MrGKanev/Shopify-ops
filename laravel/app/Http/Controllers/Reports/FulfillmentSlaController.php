<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\FulfillmentSlaResult;
use App\Application\Reports\RunFulfillmentSlaReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\FulfillmentSlaRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FulfillmentSlaController extends Controller
{
    public function create(): View
    {
        return view('reports.fulfillment-sla', $this->viewData());
    }

    public function store(FulfillmentSlaRequest $request, RunFulfillmentSlaReport $report): View
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
                $this->logFailure('Fulfillment SLA report failed.', $exception, $store);
            }
        }

        return view('reports.fulfillment-sla', $this->viewData($startDate, $endDate, $threshold, $result, $reportFailed, $configurationError));
    }

    public function export(FulfillmentSlaRequest $request, RunFulfillmentSlaReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate, $threshold] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        try {
            $result = $report->handle($store, $startDate, $endDate, $threshold);
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
    private function viewData(?string $startDate = null, ?string $endDate = null, int $threshold = 3, ?FulfillmentSlaResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('threshold', 'result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
