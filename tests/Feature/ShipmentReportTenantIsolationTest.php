<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShipmentReportTenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('unconfirmedSources')]
    public function test_reports_reject_unconfirmed_shipment_ownership_instead_of_emitting_foreign_or_zero_aggregates(string $tool, string $slug, string $fetchMethod, array $parent): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $client = $this->mock(ShipStationClientContract::class);
        $client->shouldReceive($fetchMethod)->once()->with('2026-06-01', '2026-06-30', 42)->andReturn([['shipmentId' => 1, 'orderId' => 1, 'orderNumber' => '1001', 'carrierCode' => 'PRIVATE-CARRIER', 'shipDate' => '2026-06-01', 'deliveryDate' => '2026-06-10', 'shipmentCost' => 1000, 'insuranceCost' => 0]]);
        $client->shouldReceive('getOrder')->once()->with(1)->andReturn($parent);
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($client);
        $this->mock(ShopifyOrders::class)->shouldNotReceive('shippingMarginCandidates');

        $response = $this->actingAs($operator)->post(route('reports.'.$slug.'.store'), ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'threshold' => 15]);

        $response->assertSeeText('The integration returned an unexpected response.')->assertDontSeeText('PRIVATE-CARRIER');
        $run = $store->reportRuns()->where('tool', $tool)->sole();
        $this->assertSame('failed', $run->status);
        $this->assertNull($run->result());
        $this->assertDatabaseHas('run_logs', ['store_id' => $store->id, 'tool' => $tool, 'status' => 'error', 'rows_found' => 0]);
    }

    public static function unconfirmedSources(): array
    {
        $cases = [];
        foreach (['shipping_margin' => ['shipping-margin', 'fetchShipmentsByDate'], 'carrier_performance' => ['carrier-performance', 'fetchShipmentsByDate'], 'voided_shipments' => ['voided-shipments', 'fetchVoidedShipments']] as $tool => [$slug, $fetchMethod]) {
            foreach ([
                'shared-account foreign store' => ['orderId' => 1, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 99]],
                'missing store evidence' => ['orderId' => 1, 'orderNumber' => '1001'],
                'different parent identity' => ['orderId' => 2, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 42]],
            ] as $reason => $parent) {
                $cases[$tool.' '.$reason] = [$tool, $slug, $fetchMethod, $parent];
            }
        }

        return $cases;
    }

    #[DataProvider('reports')]
    public function test_missing_store_number_prevents_an_account_wide_financial_scan(string $tool, string $slug, string $fetchMethod): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => null]);
        $client = $this->mock(ShipStationClientContract::class);
        $client->shouldNotReceive($fetchMethod);
        $client->shouldNotReceive('getOrder');
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($client);
        $this->mock(ShopifyOrders::class)->shouldNotReceive('shippingMarginCandidates');

        $this->actingAs($operator)->post(route('reports.'.$slug.'.store'), ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'threshold' => 15])->assertSeeText('could not be completed');

        $this->assertSame('failed', $store->reportRuns()->where('tool', $tool)->sole()->status);
    }

    public static function reports(): array
    {
        return ['shipping margin' => ['shipping_margin', 'shipping-margin', 'fetchShipmentsByDate'], 'carrier performance' => ['carrier_performance', 'carrier-performance', 'fetchShipmentsByDate'], 'voided labels' => ['voided_shipments', 'voided-shipments', 'fetchVoidedShipments']];
    }
}
