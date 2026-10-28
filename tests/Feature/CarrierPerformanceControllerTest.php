<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class CarrierPerformanceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_configuration_success_and_safe_failure(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/carrier-performance', ['start_date' => 'bad', 'end_date' => '2026-06-01'])->assertSessionHasErrors('start_date');
        [$operator] = $this->userWithStore(true, ['shipstation_api_key' => '']);
        $this->actingAs($operator)->post('/reports/carrier-performance', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'])->assertOk()->assertSeeText('credentials are incomplete');

        [$operator, $store] = $this->userWithStore(true);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('fetchShipmentsByDate')->once()->with('2026-06-01', '2026-06-30', (int) $store->store_number)->andReturn([['shipmentId' => 1, 'orderId' => 1, 'orderNumber' => '1001', 'carrierCode' => '<script>', 'shipDate' => '2026-06-01', 'deliveryDate' => '2026-06-03']]);
        $client->shouldReceive('getOrder')->once()->with(1)->andReturn(ShipStationFixtures::lookupOrder(1, '1001', (int) $store->store_number));
        $this->actingAs($operator)->post('/reports/carrier-performance', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'])->assertOk()->assertSeeText('1 shipments · 1 carriers')->assertDontSee('<script>', false);

        ShipStationFixtures::bindFailure(new RuntimeException('secret-token'));
        $this->actingAs($operator)->post('/reports/carrier-performance', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    /** @return array{User, Store} */
}
