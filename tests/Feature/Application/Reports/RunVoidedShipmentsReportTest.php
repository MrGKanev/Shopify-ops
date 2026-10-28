<?php

namespace Tests\Feature\Application\Reports;

use App\Application\Reports\RunVoidedShipmentsReport;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class RunVoidedShipmentsReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_report_fetches_shipments_once_and_counts_the_source_shipments(): void
    {
        $store = Store::factory()->create(['store_number' => '42']);
        $shipments = [['shipmentId' => 1, 'orderId' => 1, 'orderNumber' => '#1', 'voidDate' => '2026-06-10'], ['shipmentId' => 2, 'orderId' => 2, 'orderNumber' => '#2', 'voidDate' => '2026-06-11']];
        $client = ShipStationFixtures::bindClient(1, $store);
        $client->shouldReceive('fetchVoidedShipments')->once()->with('2026-06-01', '2026-06-30', 42)->andReturn($shipments);
        $client->shouldReceive('getOrder')->with(1)->once()->andReturn(ShipStationFixtures::lookupOrder(1, '#1'));
        $client->shouldReceive('getOrder')->with(2)->once()->andReturn(ShipStationFixtures::lookupOrder(2, '#2'));

        $result = app(RunVoidedShipmentsReport::class)->handle($store, '2026-06-01', '2026-06-30');

        $this->assertSame(2, $result->scanned);
        $this->assertSame(['#2', '#1'], array_column($result->rows, 'order_number'));
        $this->assertSame(['startDate' => '2026-06-01', 'endDate' => '2026-06-30'], $result->params);
    }
}
