<?php

namespace Tests\Feature\Operations;

use App\Application\Operations\DetectDeliveryExceptions;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\IssueStatus;
use App\Models\OperationalIssue;
use App\Models\Store;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class DetectDeliveryExceptionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_and_resolves_store_scoped_issues_from_delivery_confirmations(): void
    {
        $this->travelTo('2026-09-26 12:00:00');
        $store = Store::factory()->create(['shipstation_api_key' => 'key', 'shipstation_api_secret' => 'secret', 'store_number' => '42']);
        $otherStore = Store::factory()->create();
        $shipment = ['shipmentId' => 101, 'orderId' => 1, 'orderNumber' => '#1001', 'trackingNumber' => 'TRACK-1', 'shipDate' => now()->subDays(7)->toIso8601String(), 'carrierCode' => 'ups'];
        $delivered = [...$shipment, 'deliveryDate' => now()->subDays(1)->toIso8601String()];
        $client = $this->createMock(ShipStationClientContract::class);
        $client->expects($this->exactly(2))
            ->method('fetchShipmentsByDate')
            ->with(now()->subDays(30)->toDateString(), now()->toDateString(), 42)
            ->willReturnOnConsecutiveCalls([$shipment], [$delivered]);
        $client->expects($this->exactly(2))->method('getOrder')->with(1)->willReturn(ShipStationFixtures::lookupOrder(1, '#1001'));
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

        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
        $this->assertSame(1, $issue->fresh()->occurrences);
        $this->assertFalse($otherStore->operationalIssues()->exists());
    }

    public function test_command_skips_unconfigured_stores_and_keeps_scanning_after_a_failure(): void
    {
        Store::query()->delete();
        $broken = Store::factory()->create(['shipstation_api_key' => 'key1', 'shipstation_api_secret' => 'secret1']);
        $healthy = Store::factory()->create(['shipstation_api_key' => 'key2', 'shipstation_api_secret' => 'secret2']);
        Store::factory()->create(['shipstation_api_key' => '', 'shipstation_api_secret' => '']);
        $detector = $this->mock(DetectDeliveryExceptions::class);
        $detector->shouldReceive('handle')->with(Mockery::on(fn (Store $store): bool => $store->is($broken)))->once()->andThrow(new RuntimeException('secret-token'));
        $detector->shouldReceive('handle')->with(Mockery::on(fn (Store $store): bool => $store->is($healthy)))->once()->andReturn(3);

        $this->artisan('operations:detect-delivery-exceptions')->expectsOutput('Detected 3 delivery exception(s). 1 store scan(s) failed.')->assertFailed();
    }

    public function test_the_delivery_watch_runs_daily_without_overlap(): void
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
        $storeWithLongThreshold = Store::factory()->create(['shipstation_api_key' => 'key1', 'shipstation_api_secret' => 'secret1', 'delivery_watch_days' => 10, 'store_number' => '42']);
        $storeWithShortThreshold = Store::factory()->create(['shipstation_api_key' => 'key2', 'shipstation_api_secret' => 'secret2', 'delivery_watch_days' => 5, 'store_number' => '43']);
        $shipment = ['shipmentId' => 101, 'orderId' => 1, 'orderNumber' => '#1001', 'trackingNumber' => 'TRACK-1', 'shipDate' => now()->subDays(7)->toIso8601String()];
        $client = $this->createMock(ShipStationClientContract::class);
        $client->expects($this->exactly(2))->method('fetchShipmentsByDate')->willReturnCallback(function (string $start, string $end, ?int $storeId) use ($shipment): array {
            $this->assertSame('2026-08-27', $start);
            $this->assertSame('2026-09-26', $end);
            $this->assertContains($storeId, [42, 43]);

            return [[...$shipment, 'orderId' => $storeId]];
        });
        $client->expects($this->exactly(2))->method('getOrder')->willReturnCallback(fn (int $id): array => ShipStationFixtures::lookupOrder($id, '#1001', $id));
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

    public function test_a_shared_account_response_cannot_create_foreign_issues_or_partially_resolve_own_issues(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $store = Store::factory()->create(['store_number' => '42']);
        $other = Store::factory()->create(['store_number' => '99', 'shipstation_api_key' => $store->shipstation_api_key, 'shipstation_api_secret' => $store->shipstation_api_secret]);
        $existing = OperationalIssue::factory()->for($store)->create(['source_tool' => 'delivery_watch', 'fingerprint' => hash('sha256', 'delivery_watch|101'), 'status' => IssueStatus::InProgress]);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('fetchShipmentsByDate')->once()->with('2026-09-24', '2026-10-24', 42)->andReturn([
            ['shipmentId' => 101, 'orderId' => 1, 'orderNumber' => '#1001', 'shipDate' => '2026-10-10T00:00:00Z', 'deliveryDate' => '2026-10-23T00:00:00Z'],
            ['shipmentId' => 102, 'orderId' => 2, 'orderNumber' => '#1001', 'shipDate' => '2026-10-10T00:00:00Z'],
        ]);
        $client->shouldReceive('getOrder')->with(1)->once()->andReturn(ShipStationFixtures::lookupOrder(1, '#1001'));
        $client->shouldReceive('getOrder')->with(2)->once()->andReturn(ShipStationFixtures::lookupOrder(2, '#1001', 99));

        try {
            $this->expectException(UnexpectedResponse::class);
            app(DetectDeliveryExceptions::class)->handle($store);
        } finally {
            $this->assertSame(IssueStatus::InProgress, $existing->fresh()->status);
            $this->assertSame(1, $store->operationalIssues()->count());
            $this->assertFalse($other->operationalIssues()->exists());
        }
    }

    public function test_repeated_shipment_pages_are_deduplicated_and_parent_ownership_is_checked_once(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $store = Store::factory()->create(['store_number' => '42']);
        $shipment = ['shipmentId' => 101, 'orderId' => 1, 'orderNumber' => '#1001', 'shipDate' => '2026-10-10T00:00:00Z'];
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('fetchShipmentsByDate')->once()->andReturn([$shipment, $shipment, [...$shipment, 'shipmentId' => 102]]);
        $client->shouldReceive('getOrder')->with(1)->once()->andReturn(ShipStationFixtures::lookupOrder(1, '#1001'));

        $this->assertSame(2, app(DetectDeliveryExceptions::class)->handle($store));

        $this->assertSame(2, $store->operationalIssues()->count());
    }

    #[DataProvider('invalidParentOrders')]
    public function test_unconfirmed_parent_identity_is_a_failed_scan_not_a_successful_empty_result(array $parent): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $store = Store::factory()->create(['store_number' => '42']);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('fetchShipmentsByDate')->once()->andReturn([['shipmentId' => 101, 'orderId' => 1, 'orderNumber' => '#1001', 'shipDate' => '2026-10-10T00:00:00Z']]);
        $client->shouldReceive('getOrder')->once()->with(1)->andReturn($parent);

        try {
            $this->expectException(UnexpectedResponse::class);
            app(DetectDeliveryExceptions::class)->handle($store);
        } finally {
            $this->assertDatabaseCount('operational_issues', 0);
        }
    }

    public static function invalidParentOrders(): array
    {
        return [
            'missing store' => [['orderId' => 1, 'orderNumber' => '#1001']],
            'different parent' => [ShipStationFixtures::lookupOrder(2, '#1001')],
            'inexact number' => [ShipStationFixtures::lookupOrder(1, '#1001-extra')],
        ];
    }

    public function test_missing_store_number_does_not_fall_back_to_an_account_wide_scan(): void
    {
        $store = Store::factory()->create(['store_number' => null]);
        $this->mock(ShipStationClientFactory::class)->shouldNotReceive('forStore');

        $this->expectException(LogicException::class);
        app(DetectDeliveryExceptions::class)->handle($store);
    }
}
