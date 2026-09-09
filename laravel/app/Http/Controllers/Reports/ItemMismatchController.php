<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\ItemMismatchResult;
use App\Application\Reports\RunItemMismatchReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ItemMismatchRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ItemMismatchController extends Controller
{
    public function create(): View
    {
        return view('reports.item-mismatch', $this->viewData());
    }

    public function store(ItemMismatchRequest $request, RunItemMismatchReport $report): View
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
                $this->logFailure('Item mismatch report failed.', $exception, $store);
            }
        }

        return view('reports.item-mismatch', $this->viewData($start, $end, $result, $reportFailed, $configurationError));
    }

    public function export(ItemMismatchRequest $request, RunItemMismatchReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify and ShipStation credentials are required.']);
        }try {
            $result = $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            $this->logFailure('Item mismatch CSV failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $format = fn (array $items): string => implode('; ', array_map(fn (string $sku, int $qty): string => $sku.' ×'.$qty, array_keys($items), $items));
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['email'], $row['order_type'], $format($row['missing']), $format($row['extra']), implode('; ', $row['missing_required']), $row['total']], $result->rows);

        return $csv->download("item-mismatch-{$start}-to-{$end}.csv", ['Order', 'Date', 'Email', 'Order type', 'Missing', 'Extra', 'Missing required', 'Total'], $rows);
    }

    /** @return array{Store,string,string} */
    private function context(ItemMismatchRequest $request): array
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
    private function viewData(?string $start = null, ?string $end = null, ?ItemMismatchResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
