<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

class RouteAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_report_and_admin_route_has_its_required_gate(): void
    {
        $missing = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            $gate = match (true) {
                str_starts_with($name, 'reports.') => 'can:run-audits',
                str_starts_with($name, 'admin.') => 'can:manage-administration',
                in_array($name, ['ignored-orders.store', 'ignored-orders.bulk-destroy', 'ignored-orders.import', 'ignored-orders.destroy', 'jobs.retry', 'jobs.destroy', 'print-queue.index', 'print-queue.store', 'print-queue.clear', 'print-queue.destroy', 'orders.push.create', 'orders.push.preview', 'orders.push.store', 'orders.note.update'], true) => 'can:run-audits',
                default => null,
            };
            if ($gate && ! $this->hasMiddleware($route, $gate)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_viewer_cannot_submit_a_report(): void
    {
        $viewer = User::factory()->create();
        $viewer->stores()->attach(Store::factory()->create());

        $this->actingAs($viewer)->post('/reports/email-check')->assertForbidden();
    }

    private function hasMiddleware(Route $route, string $expected): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware === $expected || str_ends_with($middleware, ':'.substr($expected, 4))) {
                return true;
            }
        }

        return false;
    }
}
