<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_initial_prefill_and_validation_boundaries(): void
    {
        $this->get('/orders/tracking')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/orders/tracking')->assertForbidden();
        [$user] = $this->makeUserAndStore();
        $this->actingAs($user)->get(route('orders.tracking', ['prefill' => '<script>x</script>']))->assertOk()->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>', false);
        $this->from(route('orders.tracking'))->actingAs($user)->post(route('orders.tracking.store'), ['orders' => implode(' ', range(1, 31))])->assertSessionHasErrors(['orders' => 'Maximum 30 order numbers at once.']);
        foreach ([['orders' => '###'], ['orders' => ['1001']], ['orders' => '1001 OR status:any']] as $payload) {
            $this->from(route('orders.tracking'))->actingAs($user)->post(route('orders.tracking.store'), $payload)->assertSessionHasErrors('orders');
        }
    }

    public function test_validation_error_is_associated_with_the_orders_field(): void
    {
        [$user] = $this->makeUserAndStore();

        $this->from(route('orders.tracking'))->actingAs($user)->followingRedirects()->post(route('orders.tracking.store'), ['orders' => '###'])
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="orders-error"', false)
            ->assertSee('id="orders-error" role="alert"', false);
    }

    public function test_tracks_unique_orders_with_real_shipments_and_escapes_upstream_text(): void
    {
        [$user, $store] = $this->makeUserAndStore();
        $client = ShipStationFixtures::bindClient(1, $store);
        $client->shouldReceive('findByOrderNumber')->once()->with('1002', (int) $store->store_number)->andReturn([ShipStationFixtures::lookupOrder(2, '1002', (int) $store->store_number, ['orderStatus' => '<script>x</script>'])]);
        $client->shouldReceive('getOrderShipments')->once()->with('1002', true)->andReturn([['shipmentId' => 1, 'orderId' => 2, 'carrierCode' => 'UPS', 'trackingNumber' => 'ABC 1', 'shipDate' => '2026-09-01T10:00:00Z'], ['shipmentId' => 2, 'orderId' => 2, 'carrierCode' => 'unknown', 'trackingNumber' => 'T2']]);
        $client->shouldReceive('findByOrderNumber')->once()->with('1001', (int) $store->store_number)->andReturn([]);

        $this->actingAs($user)->post(route('orders.tracking.store'), ['orders' => "#1002,1002\n1001"])->assertOk()
            ->assertSeeTextInOrder(['#1002', '#1001'])->assertSeeText('2 shipments')->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>', false)
            ->assertSee('https://www.ups.com/track?tracknum=ABC+1', false)->assertSee('rel="noopener noreferrer"', false)->assertSeeText('Not found in ShipStation.');
    }

    public function test_missing_configuration_and_upstream_failure_are_safe_and_atomic(): void
    {
        [$user] = $this->makeUserAndStore();
        ShipStationFixtures::bindUnconfigured(1);
        $this->actingAs($user)->post(route('orders.tracking.store'), ['orders' => '1001'])->assertOk()->assertSeeText('not configured completely');

        ShipStationFixtures::bindFailure(new RuntimeException('private-secret'), 1);
        $this->actingAs($user)->post(route('orders.tracking.store'), ['orders' => '1001'])->assertOk()->assertSeeText('Tracking could not be loaded')->assertDontSeeText('private-secret');
    }

    public function test_shared_credentials_never_expose_another_stores_order_with_the_same_number(): void
    {
        [$user, $store] = $this->makeUserAndStore();
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('findByOrderNumber')->once()->with('1001', (int) $store->store_number)->andReturn([ShipStationFixtures::lookupOrder(99, '1001', 99999, ['orderStatus' => 'FOREIGN-ORDER'])]);
        $client->shouldNotReceive('getOrderShipments');

        $response = $this->actingAs($user)->post(route('orders.tracking.store'), ['orders' => '1001']);

        $response->assertSeeText('Not found in ShipStation.')->assertDontSeeText('FOREIGN-ORDER');
    }

    public function test_shipments_are_matched_to_the_verified_parent_order_instead_of_just_its_number(): void
    {
        [$user, $store] = $this->makeUserAndStore();
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('findByOrderNumber')->once()->with('1001', (int) $store->store_number)->andReturn([ShipStationFixtures::lookupOrder(1, '1001', (int) $store->store_number, ['orderStatus' => 'shipped'])]);
        $client->shouldReceive('getOrderShipments')->once()->with('1001', true)->andReturn([
            ['shipmentId' => 1, 'orderId' => 1, 'trackingNumber' => 'OWN-TRACKING', 'carrierCode' => 'ups'],
            ['shipmentId' => 2, 'orderId' => 99, 'trackingNumber' => 'FOREIGN-TRACKING', 'carrierCode' => 'ups'],
        ]);

        $response = $this->actingAs($user)->post(route('orders.tracking.store'), ['orders' => '1001']);

        $response->assertSeeText('OWN-TRACKING')->assertDontSeeText('FOREIGN-TRACKING');
    }

    #[DataProvider('unconfirmedOrders')]
    public function test_ambiguous_or_unconfirmed_tracking_order_identity_never_loads_shipments(array $orders): void
    {
        [$user] = $this->makeUserAndStore();
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('findByOrderNumber')->once()->andReturn($orders);
        $client->shouldNotReceive('getOrderShipments');

        $response = $this->actingAs($user)->post(route('orders.tracking.store'), ['orders' => '1001']);

        $response->assertDontSeeText('PRIVATE-STATUS');
        $response->assertViewHas('results', fn ($results): bool => $results === null || $results[0]['found'] === false);
    }

    public static function unconfirmedOrders(): array
    {
        return [
            'ambiguous order' => [[ShipStationFixtures::lookupOrder(1, '1001', extra: ['orderStatus' => 'PRIVATE-STATUS']), ShipStationFixtures::lookupOrder(2, '1001', extra: ['orderStatus' => 'PRIVATE-STATUS'])]],
            'invalid order id' => [[ShipStationFixtures::order('1001', ['orderId' => 'broken', 'orderStatus' => 'PRIVATE-STATUS'])]],
            'missing store identity' => [[['orderId' => 1, 'orderNumber' => '1001', 'orderStatus' => 'PRIVATE-STATUS']]],
            'inexact number' => [[ShipStationFixtures::lookupOrder(1, '1001-extra', extra: ['orderStatus' => 'PRIVATE-STATUS'])]],
        ];
    }

    private function makeUserAndStore(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['store_number' => '42']);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
