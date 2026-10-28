<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\Support\ShopifyFixtures;
use Tests\TestCase;

class ShippingMarginControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_configuration_success_and_safe_failure(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/shipping-margin', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'threshold' => 0])->assertSessionHasErrors('threshold');
        [$operator] = $this->userWithStore(true, ['shipstation_api_secret' => '']);
        $this->actingAs($operator)->post('/reports/shipping-margin', $this->input())->assertOk()->assertSeeText('credentials are incomplete');

        [$operator, $store] = $this->userWithStore(true);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchShipmentsByDate')->andReturn([['shipmentId' => 1, 'orderId' => 1, 'orderNumber' => '1001', 'shipDate' => '2026-06-10', 'carrierCode' => '<script>', 'shipmentCost' => 40, 'insuranceCost' => 0, 'voided' => false]]);
        $client->shouldReceive('getOrder')->with(1)->andReturn(ShipStationFixtures::lookupOrder(1, '1001', (int) $store->store_number));
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('shippingMarginCandidates')->andReturn(ShopifyFixtures::history([['id' => 2, 'order_number' => '1001', 'shipping_lines' => [['price' => 10]]]], true, 100));
        $this->app->instance(ShopifyOrders::class, $shopify);
        $this->actingAs($operator)->post('/reports/shipping-margin', $this->input())->assertOk()->assertSeeText('1 shipments scanned · 1 losses')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);

        ShipStationFixtures::bindFailure(new RuntimeException('secret-token'));
        $this->actingAs($operator)->post('/reports/shipping-margin', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchShipmentsByDate')->andReturn([['shipmentId' => 1, 'orderId' => 1, 'orderNumber' => '1001', 'carrierCode' => '=bad', 'shipmentCost' => 40, 'voided' => false]]);
        $client->shouldReceive('getOrder')->with(1)->andReturn(ShipStationFixtures::lookupOrder(1, '1001', (int) $store->store_number));
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('shippingMarginCandidates')->andReturn(ShopifyFixtures::history([['order_number' => '1001', 'shipping_lines' => []]]));
        $this->app->instance(ShopifyOrders::class, $shopify);

        $response = $this->actingAs($operator)->post(route('reports.shipping-margin.export'), $this->input());
        $response->assertOk()->assertDownload('shipping-margin-2026-06-01-to-2026-06-30.csv');
        $this->assertStringContainsString("'=bad", $response->streamedContent());
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'threshold' => 15];
    }

    /** @return array{User, Store} */
}
