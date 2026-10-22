<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunTagPolicyReport;
use App\Domain\Reports\TagPolicyAnalyzer;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TagPolicyController extends ReportController
{
    public function create(TagPolicyAnalyzer $analyzer): View
    {
        $config = $this->config();

        return view('reports.tag-policy', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'configured' => $analyzer->hasRules($config), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DateRangeReportRequest $request, RunTagPolicyReport $report, TagPolicyAnalyzer $analyzer, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $config = $this->config();
        $configured = $analyzer->hasRules($config);
        $configurationError = $configured && $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, ! $configured || $configurationError, 'tag_policy', $report::class, [$startDate, $endDate, $config], $startDate, $endDate, fn (?ReportResult $result, bool $failed) => view('reports.tag-policy', ['startDate' => $startDate, 'endDate' => $endDate, 'configured' => $configured, 'result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = config('tag-policy', []);

        return is_array($config) ? $config : [];
    }
}
