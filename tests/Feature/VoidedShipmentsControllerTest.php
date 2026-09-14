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

class VoidedShipmentsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/voided-shipments')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/voided-shipments')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/voided-shipments', ['start_date' => 'bad', 'end_date' => '2026-06-30'])->assertSessionHasErrors('start_date');
        [$operator] = $this->userWithStore(true, ['shipstation_api_key' => '']);
        $this->actingAs($operator)->post('/reports/voided-shipments', $this->input())->assertOk()->assertSeeText('credentials are incomplete');

        [$operator] = $this->userWithStore(true);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchVoidedShipments')->andReturn([['orderNumber' => '<script>', 'voidDate' => '2026-06-10']]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($operator)->post('/reports/voided-shipments', $this->input())->assertOk()->assertSeeText('1 voided shipments')->assertDontSee('<script>', false);

        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($operator)->post('/reports/voided-shipments', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchVoidedShipments')->andReturn([['orderNumber' => '=bad', 'voidDate' => '2026-06-10']]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $response = $this->actingAs($operator)->post(route('reports.voided-shipments.export'), $this->input());
        $response->assertOk()->assertDownload('voided-shipments-2026-06-01-to-2026-06-30.csv');
        $this->assertStringContainsString("'=bad", $response->streamedContent());
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
