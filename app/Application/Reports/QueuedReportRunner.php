<?php

namespace App\Application\Reports;

use App\Jobs\RunQueuedReport;
use App\Models\ReportRun;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Runs reports on the queue instead of inside the web request.
 *
 * Submitting the report form (POST) queues a new run and redirects to the report's result URL,
 * which carries the same parameters as a query string. Loading that URL (GET) finds the run by
 * its parameters and shows its result, or a "still running" page that refreshes itself. With a
 * synchronous queue (tests, local setups without a worker) the run finishes before the redirect
 * would happen, so the result is rendered straight away.
 */
class QueuedReportRunner
{
    /** How long an identical run that is still queued or running is reused instead of starting another. */
    private const int PENDING_REUSE_MINUTES = 15;

    /** How long a finished run stays reachable from its result URL. */
    private const int RESULT_LIFETIME_HOURS = 24;

    /**
     * @param  class-string  $report  an action whose handle(Store $store, ...$arguments) returns the result
     * @param  list<mixed>  $arguments  JSON-serializable arguments passed after the store
     * @param  string  $scannedMetric  result property counted as "scanned" in run history, or "count:<property>"
     * @param  string  $rowsMetric  result property counted as "rows found" in run history, or "count:<property>"
     */
    public function run(
        Request $request,
        Store $store,
        string $tool,
        string $report,
        array $arguments,
        ?string $startDate = null,
        ?string $endDate = null,
        string $scannedMetric = 'scanned',
        string $rowsMetric = 'count:rows',
    ): ReportRun {
        $hash = $this->hash($tool, $report, $arguments);
        $run = $request->isMethod('GET')
            ? $this->latest($store, $tool, $hash, now()->subHours(self::RESULT_LIFETIME_HOURS))
            : $this->latest($store, $tool, $hash, now()->subMinutes(self::PENDING_REUSE_MINUTES), pendingOnly: true);

        if ($run === null) {
            $run = $store->reportRuns()->create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'tool' => $tool,
                'report' => $report,
                'arguments' => $arguments,
                'arguments_hash' => $hash,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'scanned_metric' => $scannedMetric,
                'rows_metric' => $rowsMetric,
                'status' => 'queued',
            ]);
            RunQueuedReport::dispatch($run->getKey());
            $run->refresh();
        }

        if ($run->isPending()) {
            request()->attributes->set('pendingReportRun', $run);
        }

        return $run;
    }

    /**
     * Whether the controller should redirect to the result URL instead of rendering: only after
     * a form submission whose run has not finished yet.
     */
    public function shouldRedirect(Request $request, ReportRun $run): bool
    {
        return $run->isPending() && ! $request->isMethod('GET');
    }

    /**
     * The result URL for the submitted report: its ".result" route with the validated parameters.
     */
    public function redirectToResult(Request $request): RedirectResponse
    {
        $routeName = (string) $request->route()?->getName();
        $resultRoute = preg_replace('/\.store$/', '.result', $routeName) ?? $routeName;

        return redirect()->route($resultRoute, $request->except(['_token', '_method']));
    }

    /**
     * The result of a finished run with the same parameters, so an export does not scan the APIs again.
     *
     * @param  class-string  $report
     * @param  list<mixed>  $arguments
     */
    public function completedResult(Store $store, string $tool, string $report, array $arguments): mixed
    {
        $run = $this->latest($store, $tool, $this->hash($tool, $report, $arguments), now()->subHours(self::RESULT_LIFETIME_HOURS));

        return $run?->result();
    }

    private function latest(Store $store, string $tool, string $hash, mixed $since, bool $pendingOnly = false): ?ReportRun
    {
        return $store->reportRuns()
            ->where('tool', $tool)
            ->where('arguments_hash', $hash)
            ->where('created_at', '>=', $since)
            ->when($pendingOnly, fn ($query) => $query->whereIn('status', ['queued', 'running']))
            ->latest('id')
            ->first();
    }

    /** @param  list<mixed>  $arguments */
    private function hash(string $tool, string $report, array $arguments): string
    {
        return hash('sha256', (string) json_encode([$tool, $report, $arguments]));
    }
}
