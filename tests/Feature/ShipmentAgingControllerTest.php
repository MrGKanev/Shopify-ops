<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ShipmentAgingControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_configuration_success_and_safe_failure(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/shipment-aging', ['threshold' => 0])->assertSessionHasErrors('threshold');
        [$operator] = $this->userWithStore(true, ['shipstation_api_key' => '']);
        $this->actingAs($operator)->post('/reports/shipment-aging', ['threshold' => 3])->assertOk()->assertSeeText('credentials are incomplete');
        [$operator] = $this->userWithStore(true);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchAwaitingOrders')->andReturn([$this->order('<script>')]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($operator)->post('/reports/shipment-aging', ['threshold' => 3])->assertOk()->assertSeeText('1 awaiting orders scanned')->assertDontSee('<script>', false);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($operator)->post('/reports/shipment-aging', ['threshold' => 3])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchAwaitingOrders')->andReturn([$this->order('=bad')]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $response = $this->actingAs($operator)->post(route('reports.shipment-aging.export'), ['threshold' => 3]);
        $response->assertOk();
        $this->assertStringContainsString("'=bad", $response->streamedContent());
    }

    public function test_numeric_skus_are_preserved_in_csv_exports(): void
    {
        [$operator] = $this->userWithStore(true);
        $order = $this->order('123');
        $order['items'] = [['sku' => '12345', 'name' => 'Widget', 'quantity' => 2]];
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchAwaitingOrders')->once()->andReturn([$order]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $response = $this->actingAs($operator)->post(route('reports.shipment-aging.export'), ['threshold' => 3]);

        $response->assertOk();
        $this->assertStringContainsString('12345 ×2', $response->streamedContent());
    }

    private function order(string $number): array
    {
        return ['orderId' => 1, 'orderNumber' => $number, 'orderDate' => now()->subDays(10)->toIso8601String(), 'orderStatus' => 'awaiting_shipment', 'items' => [['sku' => 'SKU-A', 'name' => 'Widget', 'quantity' => 1]]];
    }

    /** @return array{User,Store} */
}
