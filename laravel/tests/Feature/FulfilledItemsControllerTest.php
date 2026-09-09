<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FulfilledItemsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_success_and_safe_failure(): void
    {
        $this->get('/reports/fulfilled-items')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/fulfilled-items')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/fulfilled-items', ['start_date' => 'bad', 'end_date' => '2026-07-01'])->assertSessionHasErrors('start_date');

        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('fulfilledItemCandidates')->once()->andReturn(['orders' => [['fulfillments' => [['created_at' => '2026-07-10T00:00:00Z', 'status' => 'success', 'line_items' => [['title' => '<script>', 'quantity' => 2]]]]]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/fulfilled-items', ['start_date' => '2026-07-01', 'end_date' => '2026-07-31'])->assertOk()->assertSeeText('1 orders scanned · 1 products')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);

        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('fulfilledItemCandidates')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/fulfilled-items', ['start_date' => '2026-07-01', 'end_date' => '2026-07-31'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('fulfilledItemCandidates')->once()->andReturn(['orders' => [['fulfillments' => [['created_at' => '2026-07-10T00:00:00Z', 'status' => 'success', 'line_items' => [['title' => '=HYPERLINK("bad")', 'quantity' => 2]]]]]], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $response = $this->actingAs($operator)->post(route('reports.fulfilled-items.export'), ['start_date' => '2026-07-01', 'end_date' => '2026-07-31']);
        $response->assertOk()->assertDownload('fulfilled-items-2026-07-01-to-2026-07-31.csv');
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
