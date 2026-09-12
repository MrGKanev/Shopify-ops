<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\AuditResult;
use App\Application\Reports\RunAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\ItemMismatchRequest;
use App\Jobs\RunAuditJob;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class RunAuditController extends Controller
{
    public function create(): View
    {
        return view('reports.run-audit', $this->viewData());
    }

    public function store(ItemMismatchRequest $request, RunAudit $audit): View
    {
        [$store, $start, $end] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $audit->handle($store, $start, $end);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Run audit failed.', ['exception_type' => $exception::class, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.run-audit', $this->viewData($start, $end, $result, $reportFailed, $configurationError));
    }

    public function queue(ItemMismatchRequest $request): RedirectResponse
    {
        [$store, $start, $end] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['queue' => 'Shopify and ShipStation credentials are required.']);
        }
        RunAuditJob::dispatch($store->getKey(), $start, $end);

        return back()->with('status', 'Audit queued.');
    }

    /** @return array{Store,string,string} */
    private function context(ItemMismatchRequest $request): array
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return [$request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail(), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '' || trim((string) $store->shipstation_api_key) === '' || trim((string) $store->shipstation_api_secret) === '';
    }

    /** @return array<string,mixed> */
    private function viewData(?string $start = null, ?string $end = null, ?AuditResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
