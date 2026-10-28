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

class PackingSlipTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_prefill_and_input_validation(): void
    {
        $this->get('/orders/packing-slip')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/orders/packing-slip')->assertForbidden();
        [$user] = $this->makeUserAndStore();
        $this->actingAs($user)->get(route('orders.packing-slip', ['order' => '<script>x</script>']))->assertOk()->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>', false);
        foreach ([['order_number' => '###'], ['order_number' => ['1001']], ['order_number' => str_repeat('a', 65)], ['order_number' => '1001 OR any']] as $payload) {
            $this->from(route('orders.packing-slip'))->actingAs($user)->post(route('orders.packing-slip.store'), $payload)->assertSessionHasErrors('order_number');
        }
    }

    public function test_renders_exact_match_and_rejects_zero_and_ambiguous_matches(): void
    {
        [$user, $store] = $this->makeUserAndStore();
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->with('1001', 42)->andReturn([['orderNumber' => 'similar'], ['orderId' => 1, 'advancedOptions' => ['storeId' => 42], 'orderNumber' => '1001', 'orderDate' => '2026-09-01', 'shipTo' => ['name' => '<script>x</script>'], 'items' => [['name' => 'Shirt', 'quantity' => 2, 'options' => [['name' => 'Size', 'value' => '["S","M"]']]]], 'internalNotes' => 'First<br/>Second']]);
        $client->shouldReceive('findByOrderNumber')->with('1002', 42)->andReturn([]);
        $client->shouldReceive('findByOrderNumber')->with('1003', 42)->andReturn([['orderId' => 2, 'advancedOptions' => ['storeId' => 42], 'orderNumber' => '1003'], ['orderId' => 3, 'advancedOptions' => ['storeId' => 42], 'orderNumber' => '1003']]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->times(3)->with(Mockery::on(fn (Store $received): bool => $received->is($store)))->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->post(route('orders.packing-slip.store'), ['order_number' => '#1001'])->assertOk()->assertSeeText('Packing Slip')->assertSeeText('S, M')->assertSeeTextInOrder(['First', 'Second'])->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>', false)->assertSee('data-print-window', false)->assertDontSee('onclick=', false);
        $this->actingAs($user)->post(route('orders.packing-slip.store'), ['order_number' => '1002'])->assertOk()->assertSeeText('was not found');
        $this->actingAs($user)->post(route('orders.packing-slip.store'), ['order_number' => '1003'])->assertOk()->assertSeeText('More than one exact');
    }

    public function test_missing_configuration_is_safe(): void
    {
        [$user] = $this->makeUserAndStore();
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn(null);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($user)->post(route('orders.packing-slip.store'), ['order_number' => '1001'])->assertOk()->assertSeeText('not configured completely');
    }

    public function test_lookup_failure_is_reported_without_leaking_the_error(): void
    {
        [$user] = $this->makeUserAndStore();
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->andThrow(new RuntimeException('secret-token'));
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->post(route('orders.packing-slip.store'), ['order_number' => '1001'])
            ->assertOk()->assertSeeText('could not be loaded')->assertDontSeeText('secret-token');
    }

    private function makeUserAndStore(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['store_number' => '42']);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
