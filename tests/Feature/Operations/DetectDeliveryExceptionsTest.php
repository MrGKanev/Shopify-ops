<?php

namespace Tests\Feature\Operations;

use App\Application\Operations\DetectDeliveryExceptions;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectDeliveryExceptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_and_resolves_store_scoped_issues_from_delivery_confirmations(): void
    {
        $this->travelTo('2026-09-26 12:00:00');
        $store = Store::factory()->create(['shipstation_api_key' => 'key', 'shipstation_api_secret' => 'secret']);
        $otherStore = Store::factory()->create();
        $shipment = ['shipmentId' => 101, 'orderNumber' => '#1001', 'trackingNumber' => 'TRACK-1', 'shipDate' => now()->subDays(7)->toIso8601String(), 'carrierCode' => 'ups'];
        $delivered = [...$shipment, 'deliveryDate' => now()->subDays(1)->toIso8601String()];
        $client = $this->createMock(ShipStationClientContract::class);
        $client->expects($this->exactly(2))
            ->method('fetchShipmentsByDate')
            ->with(now()->subDays(30)->toDateString(), now()->toDateString())
            ->willReturnOnConsecutiveCalls([$shipment], [$delivered]);
        $factory = $this->createMock(ShipStationClientFactory::class);
        $factory->expects($this->exactly(2))->method('forStore')->willReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $detector = app(DetectDeliveryExceptions::class);

        $this->assertSame(1, $detector->handle($store));
        $issue = $store->operationalIssues()->sole();
        $this->assertSame('delivery_watch', $issue->source_tool);
        $this->assertSame('TRACK-1', $issue->payload['tracking_number']);
        $this->assertSame('No delivery confirmation for order #1001 (7 days)', $issue->title);
        $this->assertSame(1, $issue->occurrences);

        $this->assertSame(0, $detector->handle($store));

        $this->assertSame('resolved', $issue->fresh()->status);
        $this->assertSame(1, $issue->fresh()->occurrences);
        $this->assertFalse($otherStore->operationalIssues()->exists());
    }

    public function test_the_delivery_watch_runs_hourly_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'operations:detect-delivery-exceptions'));

        $this->assertNotNull($event);
        $this->assertSame('10 7 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_delivery_watch_uses_each_stores_configured_threshold(): void
    {
        $this->travelTo('2026-09-26 12:00:00');
        $storeWithLongThreshold = Store::factory()->create(['shipstation_api_key' => 'key1', 'shipstation_api_secret' => 'secret1', 'delivery_watch_days' => 10]);
        $storeWithShortThreshold = Store::factory()->create(['shipstation_api_key' => 'key2', 'shipstation_api_secret' => 'secret2', 'delivery_watch_days' => 5]);
        $shipment = ['shipmentId' => 101, 'orderNumber' => '#1001', 'trackingNumber' => 'TRACK-1', 'shipDate' => now()->subDays(7)->toIso8601String()];
        $client = $this->createMock(ShipStationClientContract::class);
        $client->expects($this->exactly(2))->method('fetchShipmentsByDate')->with(now()->subDays(30)->toDateString(), now()->toDateString())->willReturn([$shipment]);
        $factory = $this->createMock(ShipStationClientFactory::class);
        $factory->expects($this->exactly(2))->method('forStore')->willReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $detector = app(DetectDeliveryExceptions::class);

        $this->assertSame(0, $detector->handle($storeWithLongThreshold));
        $this->assertSame(1, $detector->handle($storeWithShortThreshold));
        $this->assertDatabaseCount('operational_issues', 1);
    }

    public function test_it_skips_stores_without_shipstation_credentials(): void
    {
        $store = Store::factory()->create(['shipstation_api_key' => null, 'shipstation_api_secret' => null]);
        $factory = $this->createMock(ShipStationClientFactory::class);
        $factory->expects($this->never())->method('forStore');
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->assertSame(0, app(DetectDeliveryExceptions::class)->handle($store));
        $this->assertDatabaseCount('operational_issues', 0);
    }
}
