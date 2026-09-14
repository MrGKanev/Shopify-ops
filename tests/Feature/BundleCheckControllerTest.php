<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BundleCheckControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['order-types.rules' => [[
            'name' => 'Z1', 'match' => 'sku_starts_with', 'value' => 'z1-',
            'required_items' => [['label' => '=Cap', 'match' => 'title_contains', 'value' => 'cap']],
        ]]]);
    }

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/bundle-check')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/bundle-check')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/bundle-check', ['start_date' => 'bad', 'end_date' => '2026-06-30'])->assertSessionHasErrors('start_date');
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/bundle-check', $this->input())->assertOk()->assertSeeText('credentials are incomplete');

        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('fulfillmentSlaCandidates')->andReturn($this->candidates('<script>', true));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/bundle-check', $this->input())->assertOk()->assertSeeText('1 orders scanned · 1 incomplete bundles')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);

        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('fulfillmentSlaCandidates')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/bundle-check', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('fulfillmentSlaCandidates')->andReturn($this->candidates('=bad'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $response = $this->actingAs($operator)->post(route('reports.bundle-check.export'), $this->input());
        $response->assertOk()->assertDownload('bundle-check-2026-06-01-to-2026-06-30.csv');
        $this->assertStringContainsString("'=bad", $response->streamedContent());
        $this->assertStringContainsString("'=Cap", $response->streamedContent());
    }

    private function candidates(string $name, bool $truncated = false): array
    {
        return ['orders' => [['name' => $name, 'created_at' => '2026-06-01', 'financial_status' => 'paid', 'total_price' => 99, 'shipping_lines' => [['title' => 'Standard']], 'line_items' => [['sku' => 'z1-main', 'title' => 'Grinder']]]], 'pages' => $truncated ? 100 : 1, 'truncated' => $truncated];
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'];
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
