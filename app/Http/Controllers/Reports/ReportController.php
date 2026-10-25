<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Http\Controllers\Controller;
use App\Models\Store;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

abstract class ReportController extends Controller
{
    /**
     * Runs (or reuses) a queued report and hands the outcome to $view; skips the run when credentials are missing.
     *
     * @param  class-string  $report
     * @param  list<mixed>  $arguments
     * @param  Closure(ReportResult<array<string, mixed>>|null, bool): View  $view
     */
    protected function runQueued(QueuedReportRunner $reports, Request $request, Store $store, bool $configurationError, string $tool, string $report, array $arguments, ?string $startDate, ?string $endDate, Closure $view): View|RedirectResponse
    {
        if ($configurationError) {
            return $view(null, false);
        }
        $run = $reports->run($request, $store, $tool, $report, $arguments, $startDate, $endDate);
        if ($run->status === 'completed' && config('reports.tools.'.$tool.'.schedule_action') === $report && ! $run->result()?->truncated) {
            request()->attributes->set('scheduleSourceRun', $run);
        }
        if ($reports->shouldRedirect($request, $run)) {
            return $reports->redirectToResult($request);
        }

        return $view($run->result(), $run->hasFailed());
    }
}
