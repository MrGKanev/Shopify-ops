<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VoidedShipmentsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_configuration_success_and_safe_failure(): void
    {
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

    #[DataProvider('exportLocales')]
    public function test_export_errors_use_the_selected_locale(string $locale, bool $configured, string $message): void
    {
        app()->setLocale($locale);
        [$operator] = $this->userWithStore(true, $configured ? [] : ['shipstation_api_key' => '']);
        if ($configured) {
            $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andThrow(new RuntimeException('secret-token'));
        }

        $response = $this->actingAs($operator)->post(route('reports.voided-shipments.export'), $this->input());

        $response->assertSessionHasErrors(['export' => $message]);
    }

    public static function exportLocales(): array
    {
        return [
            'English credentials' => ['en', false, 'ShipStation credentials are incomplete for the active store.'],
            'Bulgarian credentials' => ['bg', false, 'Данните за достъп до ShipStation за активния магазин са непълни.'],
            'English failure' => ['en', true, 'The CSV export could not be completed.'],
            'Bulgarian failure' => ['bg', true, 'CSV експортът не можа да бъде завършен.'],
        ];
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'];
    }

    /** @return array{User, Store} */
}
