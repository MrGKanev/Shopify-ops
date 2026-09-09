<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\PostShipAddressChangeResult;
use App\Application\Reports\RunPostShipAddressChangeReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressChangeRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PostShipAddressChangeController extends Controller
{
    public function create(): View
    {
        return view('reports.post-ship-address-changes', $this->viewData());
    }

    public function store(AddressChangeRequest $request, RunPostShipAddressChangeReport $report): View
    {
        [$store, $startDate, $endDate] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Post-ship address change report failed.', $exception, $store);
            }
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
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['fulfillment_at'], $row['changed_at'], $row['mins_after_ship'], $row['email'], $row['addr_name'], $row['addr_line'], number_format($row['total'], 2, '.', ''), $row['financial'], $row['fulfillment']], $result->rows);

        return $csv->download("post-ship-address-changes-{$startDate}-to-{$endDate}.csv", ['Order', 'Placed', 'First fulfillment', 'Changed', 'Minutes after shipment', 'Email', 'Address name', 'Current shipping address', 'Total', 'Financial status', 'Fulfillment status'], $rows);
    }

    /** @return array{Store, string, string} */
    private function context(AddressChangeRequest $request): array
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return [$request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail(), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
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
    private function viewData(?string $startDate = null, ?string $endDate = null, ?PostShipAddressChangeResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
