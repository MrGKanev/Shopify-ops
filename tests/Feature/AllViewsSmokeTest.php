<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AllViewsSmokeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_application_screens_return_successful_html(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        $shopify = Mockery::mock(ShopifyTransport::class);
        $shopify->shouldReceive('get')->zeroOrMoreTimes()->andReturn(['webhooks' => []]);
        $this->app->instance(ShopifyTransport::class, $shopify);
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->timestamp);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $screens = $this->applicationScreenRoutes();
        $this->assertNotEmpty($screens);
        foreach ($screens as $name) {
            $response = $this->get(route($name));
            $response->assertOk()->assertSee('<html lang="en">', false);
            $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'), $name);
        }
    }

    public function test_skipped_screen_routes_are_an_explicit_list(): void
    {
        $skipped = $this->skippedApplicationScreenRoutes();
        $results = array_values(array_filter($skipped, fn (string $name): bool => str_ends_with($name, '.result')));

        $this->assertSame([
            'admin.backups.download',
            'admin.report-schedules.edit',
            'admin.stores.edit',
            'admin.users.edit',
            'carrier-claims.documents.download',
            'carrier-claims.export',
            'carrier-claims.show',
            'command-palette',
            'operational-fields.export',
            'orders.remediation.show',
            'print-queue.consolidation.export',
            'print-queue.pick-list.export',
            'print-queue.pick-list.snapshots.export',
            'print-queue.pick-list.snapshots.show',
            'saved-reports.export',
            'saved-reports.show',
            'shipping-billing.export',
            'shipping-billing.template',
        ], array_values(array_diff($skipped, $results)));
        $this->assertNotEmpty($results);
        foreach ($results as $name) {
            $this->assertMatchesRegularExpression('/^(reports\.[a-z-]+|admin\.report-schedules)\.result$/', $name, 'Only report result fragments may be skipped as ".result" routes.');
        }
    }

    public function test_authenticated_screens_refuse_a_user_without_a_store_instead_of_crashing(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        foreach ($this->applicationScreenRoutes() as $name) {
            $this->get(route($name))->assertForbidden();
        }
    }
}
