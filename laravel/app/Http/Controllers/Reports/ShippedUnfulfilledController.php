<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\RunShippedUnfulfilledReport;
use App\Application\Reports\ShippedUnfulfilledResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShippedUnfulfilledRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShippedUnfulfilledController extends Controller
{
    public function create(): View
    {
        return view('reports.shipped-unfulfilled', $this->viewData());
    }

    public function store(ShippedUnfulfilledRequest $request, RunShippedUnfulfilledReport $report): View
    {
        [$store,$start,$end] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $start, $end);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Shipped/unfulfilled report failed.', $exception, $store);
            }
        }

return view('reports.shipped-unfulfilled', $this->viewData($start, $end, $result, $reportFailed, $configurationError));
    }

    public function export(ShippedUnfulfilledRequest $request, RunShippedUnfulfilledReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify and ShipStation credentials are required.']);
        }try {
            $result = $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            $this->logFailure('Shipped/unfulfilled CSV failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }$rows = array_map(fn (array $row): array => [$row['order_number'], $row['order_date'], $row['customer'], $row['email'], 'shipped', $row['sh_fulfillment'], $row['sh_financial'], $row['total']], $result->rows);

        return $csv->download("shipped-unfulfilled-{$start}-to-{$end}.csv", ['Order', 'Date', 'Customer', 'Email', 'ShipStation status', 'Shopify fulfillment', 'Shopify financial', 'Total'], $rows);
    }

    /** @return array{Store,string,string} */
    private function context(ShippedUnfulfilledRequest $request): array
    {/** @var Store $activeStore */ $activeStore = $request->attributes->get('activeStore');

        return [$request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail(), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '' || trim((string) $store->shipstation_api_key) === '' || trim((string) $store->shipstation_api_secret) === '';
    }

    private function logFailure(string $message, Throwable $exception, Store $store): void
    {
        Log::warning($message, ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
    }

    /** @return array<string,mixed> */
    private function viewData(?string $start = null, ?string $end = null, ?ShippedUnfulfilledResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
