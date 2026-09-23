<?php

namespace App\Http\Controllers\Concerns;

use App\Application\Health\BuildOperationalHealth;
use App\Application\Health\CheckConfiguration;
use App\Models\Store;

trait BuildsDiagnosticsView
{
    /** @return array<string, mixed> */
    private function diagnosticsViewData(BuildOperationalHealth $health, CheckConfiguration $check, Store $store, string $activeTab): array
    {
        return [
            'activeTab' => $activeTab,
            'checks' => $health->handle(),
            'results' => $check->handle($store),
            'quickLinks' => [
                ['label' => 'API Health', 'route' => 'admin.api-health'],
                ['label' => 'Webhook Health', 'route' => 'admin.webhook-health'],
                ['label' => 'Webhook Events', 'route' => 'admin.webhook-events'],
                ['label' => 'Backups', 'route' => 'admin.backups.index'],
                ['label' => 'Incident History', 'route' => 'admin.health-incidents'],
                ['label' => 'Action Log', 'route' => 'admin.action-log'],
                ['label' => 'Job Queue', 'route' => 'jobs.index'],
                ['label' => 'Run History', 'route' => 'run-logs.index'],
                ['label' => 'Horizon', 'route' => 'horizon.index'],
                ['label' => 'Pulse', 'route' => 'pulse'],
                ['label' => 'Public readiness', 'route' => 'ready'],
                ['label' => 'Public status', 'route' => 'status'],
            ],
        ];
    }
}
