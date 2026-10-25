<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunShipmentMovementReport;
use App\Http\Requests\ShipmentMovementRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ShipmentMovementController extends ReportController
{
    public function create(): View
    {
        return view('reports.shipment-movement', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ShipmentMovementRequest $request, RunShipmentMovementReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShipStationCredentials() || trim((string) $store->shipstation_v2_api_key) === '' || ! ctype_digit((string) $store->store_number) || (int) $store->store_number < 1;
        $arguments = [(string) $request->validated('order_number'), (int) $request->validated('acceptance_hours'), (int) $request->validated('stale_hours')];

        if ($request->isMethod('GET')) {
            $query = $store->reportRuns()->where('tool', 'shipment_movement')->where('report', $report::class);
            if ($request->input('report_run') !== null) {
                $selectedId = filter_var($request->input('report_run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                abort_if($selectedId === false, 404);
                $query->whereKey($selectedId);
            } else {
                $query->where('arguments->[0]', $arguments[0])->where('arguments->[1]', $arguments[1])->where('arguments->[2]', $arguments[2]);
            }
            $run = $query->latest('id')->firstOrFail();
            if ($run->hasFailed() && $run->failure_reason !== null) {
                request()->attributes->set('reportFailureReason', $run->failure_reason);
            }
            if ($run->isPending()) {
                request()->attributes->set('pendingReportRun', $run);
            }

            return view('reports.shipment-movement', ['result' => $run->result(), 'reportFailed' => $run->hasFailed(), 'configurationError' => $configurationError]);
        }

        return $this->runQueued($reports, $request, $store, $configurationError, 'shipment_movement', $report::class, $arguments, null, null, fn (?ReportResult $result, bool $failed): View => view('reports.shipment-movement', ['result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
