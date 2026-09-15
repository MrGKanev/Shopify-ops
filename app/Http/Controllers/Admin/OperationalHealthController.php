<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\BuildOperationalHealth;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class OperationalHealthController extends Controller
{
    public function __invoke(BuildOperationalHealth $health): View
    {
        return view('admin.health', [
            'checks' => $health->handle(),
            'quickLinks' => [
                ['label' => 'API Health', 'route' => 'admin.api-health'],
                ['label' => 'Backups', 'route' => 'admin.backups.index'],
                ['label' => 'Incident History', 'route' => 'admin.health-incidents'],
                ['label' => 'Job Queue', 'route' => 'jobs.index'],
                ['label' => 'Run History', 'route' => 'run-logs.index'],
                ['label' => 'Horizon', 'route' => 'horizon.index'],
                ['label' => 'Pulse', 'route' => 'pulse'],
                ['label' => 'Public readiness', 'route' => 'ready'],
                ['label' => 'Public status', 'route' => 'status'],
            ],
        ]);
    }
}
