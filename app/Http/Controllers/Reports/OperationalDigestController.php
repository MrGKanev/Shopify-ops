<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunOperationalDigest;
use App\Http\Controllers\Controller;
use App\Http\Requests\FulfillmentSlaRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationalDigestController extends Controller
{
    public function create(Request $request): View
    {
        $store = $this->resolveStore($request);
        $policy = $store->operationalDigestPolicy();

        return view('reports.operational-digest', $this->viewData(now($store->shopTimezone())->subDays($policy['lookback_days'] - 1)->toDateString(), now($store->shopTimezone())->toDateString(), $policy['sla_days']));
    }

    public function store(FulfillmentSlaRequest $request, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $start = $request->validated('start_date');
        $end = $request->validated('end_date');
        $threshold = (int) $request->validated('threshold');
        $run = $reports->run($request, $store, 'operational_digest', RunOperationalDigest::class, [$start, $end, $threshold], $start, $end);
        if ($reports->shouldRedirect($request, $run)) {
            return $reports->redirectToResult($request);
        }

        return view('reports.operational-digest', $this->viewData($start, $end, $threshold, $run->result(), $run->hasFailed()));
    }

    /** @param ReportResult<array<string, mixed>>|null $result
     * @return array<string, mixed> */
    private function viewData(string $start, string $end, int $threshold, ?ReportResult $result = null, bool $reportFailed = false): array
    {
        return ['startDate' => $start, 'endDate' => $end, 'threshold' => $threshold, 'result' => $result, 'reportFailed' => $reportFailed];
    }
}
