<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RunTagPolicyReport;
use App\Application\Reports\ScanResult;
use App\Domain\Reports\TagPolicyAnalyzer;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\TagPolicyRequest;
use Illuminate\View\View;
use Throwable;

class TagPolicyController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(TagPolicyAnalyzer $analyzer): View
    {
        $config = $this->config();

        return view('reports.tag-policy', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'configured' => $analyzer->hasRules($config), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(TagPolicyRequest $request, RunTagPolicyReport $report, TagPolicyAnalyzer $analyzer, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $config = $this->config();
        $configured = $analyzer->hasRules($config);
        $configurationError = $configured && $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if ($configured && ! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate, $config);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Tag policy report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'tag_policy', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.tag-policy', ['startDate' => $startDate, 'endDate' => $endDate, 'configured' => $configured, 'result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = config('tag-policy', []);

        return is_array($config) ? $config : [];
    }
}
