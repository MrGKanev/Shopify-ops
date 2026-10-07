<?php

namespace Tests\Feature\Reports;

use App\Application\Reports\ReportRegistry;
use App\Models\RateQuoteSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('reportTools')]
    public function test_report_endpoints_require_authentication_and_operator_permission(string $key): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $tool = app(ReportRegistry::class)->find($key);
        $snapshot = $key === 'rate_shopping' ? RateQuoteSnapshot::factory()->create() : null;
        $endpoints = [['GET', $tool->route], ['POST', $tool->route.'.store']];
        foreach (['result', 'export', 'queue'] as $action) {
            if (Route::has($tool->route.'.'.$action)) {
                $endpoints[] = [$action === 'result' ? 'GET' : 'POST', $tool->route.'.'.$action];
            }
        }
        foreach ($endpoints as [$method, $route]) {
            $this->call($method, route($route, $snapshot !== null && str_ends_with($route, '.result') ? ['snapshot' => $snapshot->id] : []))->assertRedirect(route('login'));
        }
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer);

        foreach ($endpoints as [$method, $route]) {
            $this->call($method, route($route, $snapshot !== null && str_ends_with($route, '.result') ? ['snapshot' => $snapshot->id] : []))->assertForbidden();
        }

        $this->assertDatabaseCount('report_runs', 0);
        $this->assertDatabaseCount('run_logs', 0);
        Queue::assertNothingPushed();
    }

    public static function reportTools(): array
    {
        $registry = new ReportRegistry(require __DIR__.'/../../../config/reports.php');

        return array_map(fn (string $key): array => [$key], array_combine(array_keys($registry->recordingTools()), array_keys($registry->recordingTools())));
    }
}
