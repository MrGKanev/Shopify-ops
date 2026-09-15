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

class ShippingMarginControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/shipping-margin')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/shipping-margin')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/shipping-margin', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'threshold' => 0])->assertSessionHasErrors('threshold');
        [$operator] = $this->userWithStore(true, ['shipstation_api_secret' => '']);
        $this->actingAs($operator)->post('/reports/shipping-margin', $this->input())->assertOk()->assertSeeText('credentials are incomplete');

        [$operator] = $this->userWithStore(true);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchShipmentsByDate')->andReturn([['orderId' => 1, 'orderNumber' => '1001', 'shipDate' => '2026-06-10', 'carrierCode' => '<script>', 'shipmentCost' => 40, 'insuranceCost' => 0, 'voided' => false]]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('shippingMarginCandidates')->andReturn(['orders' => [['id' => 2, 'order_number' => '1001', 'shipping_lines' => [['price' => 10]]]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/shipping-margin', $this->input())->assertOk()->assertSeeText('1 shipments scanned · 1 losses')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);

        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($operator)->post('/reports/shipping-margin', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchShipmentsByDate')->andReturn([['orderNumber' => '1001', 'carrierCode' => '=bad', 'shipmentCost' => 40, 'voided' => false]]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('shippingMarginCandidates')->andReturn(['orders' => [['order_number' => '1001', 'shipping_lines' => []]], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $response = $this->actingAs($operator)->post(route('reports.shipping-margin.export'), $this->input());
        $response->assertOk()->assertDownload('shipping-margin-2026-06-01-to-2026-06-30.csv');
        $this->assertStringContainsString("'=bad", $response->streamedContent());
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'threshold' => 15];
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
