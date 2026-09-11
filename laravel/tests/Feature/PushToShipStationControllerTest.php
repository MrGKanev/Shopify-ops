<?php

namespace Tests\Feature;

use App\Application\Orders\PushOrderToShipStation;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PushToShipStationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_an_operator_can_access_the_page_and_push_an_order(): void
    {
        $this->get(route('orders.push.create'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore(false);
        $this->actingAs($viewer)->get(route('orders.push.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('orders.push.store'), ['order_number' => '1001'])->assertForbidden();

        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->get(route('orders.push.create'))->assertOk();
    }

    public function test_store_pushes_the_order_and_flashes_a_status_message(): void
    {
        [$operator] = $this->userWithStore(true);
        $push = Mockery::mock(PushOrderToShipStation::class);
        $push->shouldReceive('handle')->once()->with(Mockery::type(Store::class), '1001')->andReturn(['order_number' => '1001', 'shopify_order_number' => '1001', 'ss_order_id' => 555]);
        $this->app->instance(PushOrderToShipStation::class, $push);

        $this->actingAs($operator)->post(route('orders.push.store'), ['order_number' => '#1001'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Pushed order #1001 to ShipStation.');
    }

    public function test_store_redirects_with_an_error_when_the_order_is_not_found(): void
    {
        [$operator] = $this->userWithStore(true);
        $push = Mockery::mock(PushOrderToShipStation::class);
        $push->shouldReceive('handle')->once()->andThrow(new RuntimeException('Order 1001 not found in Shopify.'));
        $this->app->instance(PushOrderToShipStation::class, $push);

        $this->actingAs($operator)->post(route('orders.push.store'), ['order_number' => '1001'])
            ->assertRedirect()
            ->assertSessionHasErrors('order_number');
    }

    public function test_preview_returns_the_payload_as_json(): void
    {
        [$operator] = $this->userWithStore(true);
        $push = Mockery::mock(PushOrderToShipStation::class);
        $push->shouldReceive('preview')->once()->with(Mockery::type(Store::class), '1001')->andReturn(['orderNumber' => '1001']);
        $this->app->instance(PushOrderToShipStation::class, $push);

        $this->actingAs($operator)->post(route('orders.push.preview'), ['order_number' => '1001'])
            ->assertOk()
            ->assertJson(['payload' => ['orderNumber' => '1001']]);
    }

    public function test_preview_returns_a_safe_error_message_without_exposing_internals(): void
    {
        [$operator] = $this->userWithStore(true);
        $push = Mockery::mock(PushOrderToShipStation::class);
        $push->shouldReceive('preview')->once()->andThrow(new LogicException('ShipStation credentials are required.'));
        $this->app->instance(PushOrderToShipStation::class, $push);

        $this->actingAs($operator)->post(route('orders.push.preview'), ['order_number' => '1001'])
            ->assertStatus(422)
            ->assertJson(['error' => 'ShipStation credentials are required.']);
    }

    public function test_order_number_is_required_and_validated(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->post(route('orders.push.store'), ['order_number' => ''])->assertSessionHasErrors('order_number');
        $this->actingAs($operator)->post(route('orders.push.store'), ['order_number' => 'bad number!'])->assertSessionHasErrors('order_number');
    }

    public function test_a_real_push_persists_a_push_log_row(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['shipstation_api_key' => 'ss-key', 'shipstation_api_secret' => 'ss-secret']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '1001')->andReturn([['id' => 42, 'name' => '#1001', 'total_price' => '10.00']]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        Http::fake(['https://ssapi.shipstation.com/orders/createorder' => Http::response(['orderId' => 555, 'orderNumber' => '1001'])]);

        $this->actingAs($operator)->post(route('orders.push.store'), ['order_number' => '1001'])->assertRedirect();

        $this->assertDatabaseHas('push_logs', ['store_id' => $store->getKey(), 'order_number' => '1001', 'shopify_id' => '42', 'shipstation_order_id' => 555]);
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $operator, array $storeAttributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($storeAttributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
