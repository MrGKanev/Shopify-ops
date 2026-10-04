<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\ReportResult;
use App\Application\Reports\RunAudit;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use App\Jobs\RunAuditJob;
use App\Models\AuditJob;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class RunAuditController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.run-audit', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunAudit $audit): View
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
                $this->logFailure('Run audit failed.', $exception, $store);
            }
        }

        return view('reports.run-audit', $this->viewData($start, $end, $result, $reportFailed, $configurationError));
    }

    public function queue(DateRangeReportRequest $request): RedirectResponse
    {
        [$store, $start, $end] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['queue' => __('reports.integration_credentials_required')]);
        }
        $pending = AuditJob::where('store_id', $store->getKey())->where('start_date', $start)->where('end_date', $end)->whereIn('status', ['queued', 'running'])->exists();
        if ($pending) {
            return back()->with('status', __('reports.audit_already_queued'));
        }
        $auditJob = AuditJob::create(['store_id' => $store->getKey(), 'start_date' => $start, 'end_date' => $end]);
        RunAuditJob::dispatch($store->getKey(), $start, $end, $auditJob->getKey());
        activity('operator-actions')->causedBy($request->user())->performedOn($store)
            ->withProperties(['start_date' => $start, 'end_date' => $end])->log('queue_audit');

        return back()->with('status', __('reports.audit_queued'));
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

    /**
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @return array<string,mixed>
     */
    private function viewData(?string $start = null, ?string $end = null, ?ReportResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
