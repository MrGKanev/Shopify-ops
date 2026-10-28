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

class OrphanOrderControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_configuration_success_and_safe_failure(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->actingAs($operator)->post('/reports/orphan-orders', ['start_date' => 'bad', 'end_date' => '2026-06-30'])->assertSessionHasErrors('start_date');
        [$operator] = $this->userWithStore(true, ['shipstation_api_key' => '']);
        $this->actingAs($operator)->post('/reports/orphan-orders', $this->input())->assertOk()->assertSeeText('credentials are required');
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->bindSuccess('<script>', true);
        $this->actingAs($operator)->post('/reports/orphan-orders', $this->input())->assertOk()->assertSeeText('1 ShipStation vs 0 Shopify orders')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);
        ShipStationFixtures::bindFailure(new RuntimeException('secret-token'));
        $this->actingAs($operator)->post('/reports/orphan-orders', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->bindSuccess('=bad');
        $response = $this->actingAs($operator)->post(route('reports.orphan-orders.export'), $this->input());
        $response->assertOk();
        $this->assertStringContainsString("'=bad", $response->streamedContent());
    }

    private function bindSuccess(string $customer, bool $truncated = false): void
    {
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchAllOrders')->andReturn([['orderId' => 1, 'advancedOptions' => ['storeId' => 42], 'orderNumber' => '9999', 'orderDate' => '2026-06-01', 'orderStatus' => 'awaiting_shipment', 'shipTo' => ['name' => $customer]]]);
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('itemMismatchCandidates')->andReturn(ShopifyFixtures::history([], $truncated, $truncated ? 100 : 1));
        $this->app->instance(ShopifyOrders::class, $gateway);
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'];
    }

    /** @return array{User,Store} */
}
