<?php

namespace App\Http\Controllers;

use App\Application\Orders\AuditPreShipmentDrift;
use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use LogicException;
use Throwable;

class PreShipmentDriftController extends Controller
{
    public function show(Request $request, AuditPreShipmentDrift $audit): View
    {
        $request->validate(['state' => ['nullable', 'string', 'in:all,different,address,unknown,match']]);
        $store = $this->resolveStore($request);
        $state = $request->string('state')->toString() ?: 'issues';
        $result = null;
        $failure = null;
        if ($request->boolean('generate')) {
            try {
                $result = $audit->handle($store);
                $result['counts'] = array_count_values(array_column($result['rows'], 'state'));
                if (! in_array($state, ['all', 'issues'], true)) {
                    $result['rows'] = array_values(array_filter($result['rows'], fn (array $row): bool => $row['state'] === $state));
                } elseif ($state === 'issues') {
                    $result['rows'] = array_values(array_filter($result['rows'], fn (array $row): bool => $row['state'] !== 'match'));
                }
            } catch (LogicException) {
                $failure = 'configuration';
            } catch (Throwable $exception) {
                $failure = 'lookup';
                Log::warning('Pre-shipment drift audit failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof IntegrationException ? $exception->status : null]);
            }
        }

        return view('print-queue.drift-audit', ['result' => $result, 'failure' => $failure, 'state' => $state, 'max' => AuditPreShipmentDrift::MAX_ORDERS]);
    }
}
