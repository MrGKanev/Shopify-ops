<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\NoteFlagResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunNoteFlagReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\NoteFlagRequest;
use Illuminate\View\View;
use Throwable;

class NoteFlagController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    private const DEFAULT_KEYWORDS = 'urgent, hold, cancel, wrong, error, stop, do not ship, dont ship, wait, attention';

    public function create(): View
    {
        return view('reports.note-flags', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'keywords' => self::DEFAULT_KEYWORDS, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(NoteFlagRequest $request, RunNoteFlagReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $keywordsRaw = (string) $request->validated('keywords');
        $keywords = array_values(array_filter(array_map(fn (string $keyword): string => mb_strtolower(trim($keyword)), explode(',', $keywordsRaw))));
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate, $keywords);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Note flags report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'note_flags', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.note-flags', ['startDate' => $startDate, 'endDate' => $endDate, 'keywords' => $keywordsRaw, 'result' => $result instanceof NoteFlagResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
