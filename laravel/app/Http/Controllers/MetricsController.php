<?php

namespace App\Http\Controllers;

use App\Application\Health\CollectMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __invoke(Request $request, CollectMetrics $metrics): Response
    {
        $token = trim((string) config('services.metrics.token'));

        if ($token === '') {
            abort(404);
        }

        $provided = str((string) $request->header('Authorization'))->after('Bearer ')->toString();

        if (! hash_equals($token, $provided)) {
            abort(401);
        }

        return response($metrics->handle(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}
