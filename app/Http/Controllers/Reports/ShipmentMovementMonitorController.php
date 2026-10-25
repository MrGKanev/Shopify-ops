<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunShipmentMovementMonitorReport;
use App\Http\Requests\ShipmentMovementMonitorRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ShipmentMovementMonitorController extends ReportController
{
    public function create(): View
    {
        return view('reports.shipment-movement-monitor', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ShipmentMovementMonitorRequest $request, RunShipmentMovementMonitorReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $configurationError = $store->missingShipStationCredentials() || trim((string) $store->shipstation_v2_api_key) === '' || ! $store->movementPolicy()['access_verified'];
        if ($request->isMethod('GET')) {
            $query = $store->reportRuns()->where('tool', 'shipment_movement_monitor')->where('report', $report::class);
            if ($request->input('report_run') !== null) {
                $selectedId = filter_var($request->input('report_run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                abort_if($selectedId === false, 404);
                $query->whereKey($selectedId);
            }
            $run = $query->latest('id')->firstOrFail();
            if ($run->hasFailed() && $run->failure_reason !== null) {
                request()->attributes->set('reportFailureReason', $run->failure_reason);
            }
            if ($run->isPending()) {
                request()->attributes->set('pendingReportRun', $run);
            }

            return view('reports.shipment-movement-monitor', ['result' => $run->result(), 'reportFailed' => $run->hasFailed(), 'configurationError' => $configurationError]);
        }

        return $this->runQueued($reports, $request, $store, $configurationError, 'shipment_movement_monitor', $report::class, [], null, null, fn (?ReportResult $result, bool $failed): View => view('reports.shipment-movement-monitor', ['result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
