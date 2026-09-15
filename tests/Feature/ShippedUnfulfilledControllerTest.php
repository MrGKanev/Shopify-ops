<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ShippedUnfulfilledControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/shipped-unfulfilled')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/shipped-unfulfilled')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/shipped-unfulfilled', ['start_date' => 'bad', 'end_date' => '2026-06-30'])->assertSessionHasErrors('start_date');
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/shipped-unfulfilled', $this->input())->assertOk()->assertSeeText('credentials are required');
        [$operator] = $this->userWithStore(true);
        $this->bindSuccess('<script>', true);
        $this->actingAs($operator)->post('/reports/shipped-unfulfilled', $this->input())->assertOk()->assertSeeText('1 SS shipped orders')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($operator)->post('/reports/shipped-unfulfilled', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->bindSuccess('=bad');
        $response = $this->actingAs($operator)->post(route('reports.shipped-unfulfilled.export'), $this->input());
        $response->assertOk();
        $this->assertStringContainsString("'=bad", $response->streamedContent());
    }

    private function bindSuccess(string $email, bool $truncated = false): void
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchAllOrders')->andReturn([['orderId' => 1, 'orderNumber' => '1001', 'orderStatus' => 'shipped', 'orderDate' => '2026-06-01', 'customerEmail' => $email]]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('itemMismatchCandidates')->andReturn(['orders' => [['id' => 1, 'name' => '#1001', 'fulfillment_status' => null, 'financial_status' => 'paid']], 'pages' => $truncated ? 100 : 1, 'truncated' => $truncated]);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'];
    }

    /** @return array{User,Store} */
    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
