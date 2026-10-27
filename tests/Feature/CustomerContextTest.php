<?php

namespace Tests\Feature;

use App\Domain\Orders\CustomerFulfillmentContext;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyCustomers;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\CustomerContextFilter;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CustomerContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{name: string, created_at: string, cancelled: bool, test: bool, customer_id: string, skus: list<string>, lines_complete: bool} */
    private function order(string $name, string $at, array $overrides = []): array
    {
        return $overrides + ['name' => $name, 'created_at' => $at, 'cancelled' => false, 'test' => false, 'customer_id' => 'c1', 'skus' => [], 'lines_complete' => true];
    }

    public function test_first_order_requires_complete_history(): void
    {
        $analyzer = new CustomerFulfillmentContext;
        $history = [$this->order('#1002', '2026-10-02T10:00:00Z')];

        $first = $analyzer->classify('a@example.com', '1002', $history, false, ['TEE']);
        $this->assertSame('first', $first['status']);
        $this->assertContains('no_prior_orders', $first['reasons']);

        $truncated = $analyzer->classify('a@example.com', '1002', $history, true, ['TEE']);
        $this->assertSame('unknown', $truncated['status']);
        $this->assertSame(['history_truncated'], $truncated['reasons']);

        $this->assertSame('unknown', $analyzer->classify('a@example.com', '9999', $history, false, [])['status']);
        $this->assertSame('unknown', $analyzer->classify('', '1002', $history, false, [])['status']);
        $this->assertSame('unknown', $analyzer->classify('a@example.com', '1002', [$this->order('#1002', 'not a date')], false, [])['status']);
    }

    public function test_repeat_counts_only_earlier_valid_orders_of_the_same_customer(): void
    {
        $history = [
            $this->order('#1005', '2026-10-05T10:00:00Z'),
            $this->order('#1004', '2026-10-04T10:00:00Z', ['skus' => ['tee', 'mug']]),
            $this->order('#1003', '2026-10-03T10:00:00Z', ['cancelled' => true, 'skus' => ['hat']]),
            $this->order('#1002', '2026-10-02T10:00:00Z', ['test' => true]),
            $this->order('#1001', '2026-10-01T10:00:00Z', ['customer_id' => 'other']),
            $this->order('#1006', '2026-10-06T10:00:00Z'),
        ];

        $result = (new CustomerFulfillmentContext)->classify('a@example.com', '#1005', $history, false, ['TEE', 'HAT']);

        $this->assertSame('repeat', $result['status']);
        $this->assertSame(1, $result['priorOrders']);
        $this->assertFalse($result['atLeast']);
        $this->assertSame(['tee'], $result['repeatedSkus']);
        $this->assertNull($result['tier']);
    }

    public function test_truncated_history_with_prior_orders_is_a_minimum_and_tiers_apply(): void
    {
        $history = [$this->order('#5', '2026-10-05T10:00:00Z', ['lines_complete' => false]), $this->order('#4', '2026-10-04T10:00:00Z'), $this->order('#3', '2026-10-03T10:00:00Z'), $this->order('#2', '2026-10-02T10:00:00Z'), $this->order('#1', '2026-10-01T10:00:00Z')];
        $analyzer = new CustomerFulfillmentContext;

        $vip = $analyzer->classify('a@example.com', '5', $history, true, [], 5);
        $this->assertSame('repeat', $vip['status']);
        $this->assertTrue($vip['atLeast']);
        $this->assertSame(4, $vip['priorOrders']);
        $this->assertSame('vip', $vip['tier']);
        $this->assertContains('history_incomplete', $vip['reasons']);

        $this->assertTrue($analyzer->matches($vip, 'vip'));
        $this->assertTrue($analyzer->matches($vip, 'all'));
        $this->assertFalse($analyzer->matches($vip, 'first'));
        $this->assertFalse($analyzer->matches($vip, 'sku_repeat'));
        $this->assertSame('milestone', $analyzer->classify('a@example.com', '5', $history, false, [], 6)['tier']);
        $this->assertNull($analyzer->classify('a@example.com', '4', $history, false, [], 6)['tier']);
    }

    public function test_client_reads_skus_and_flags_incomplete_line_items(): void
    {
        Http::preventStrayRequests();
        $node = fn (string $name, bool $more, ?string $sku): array => ['node' => ['legacyResourceId' => '1', 'name' => $name, 'createdAt' => '2026-09-02T00:00:00Z', 'cancelledAt' => null, 'test' => false, 'customer' => ['id' => 'gid://shopify/Customer/1'], 'lineItems' => ['pageInfo' => ['hasNextPage' => $more], 'edges' => [['node' => ['sku' => $sku, 'quantity' => 1]], ['node' => ['sku' => '', 'quantity' => 1]]]]]];
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [$node('#1', false, ' TEE '), $node('#2', true, null)]]]])]);

        $result = app(ShopifyAdminClient::class)->customerFulfillmentHistory(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), ' Jane@Example.com ');

        $this->assertSame(['tee'], $result['orders'][0]['skus']);
        $this->assertTrue($result['orders'][0]['lines_complete']);
        $this->assertFalse($result['orders'][1]['lines_complete']);
        $this->assertSame('gid://shopify/Customer/1', $result['orders'][0]['customer_id']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['variables']['search'], 'email:"jane@example.com"'));
    }

    public function test_page_is_store_scoped_filters_by_rule_and_never_calls_mutations(): void
    {
        $this->get(route('print-queue.customer-context'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('print-queue.customer-context'))->assertForbidden();

        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        foreach (['1001', '1002', '1003', '1004'] as $number) {
            $store->printQueueItems()->create(['order_number' => $number]);
        }
        $ship = fn (string $number, string $email): array => ['orderId' => (int) $number, 'orderNumber' => $number, 'customerEmail' => $email, 'advancedOptions' => ['storeId' => 42], 'items' => [['sku' => 'TEE', 'quantity' => 1]]];
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->with('1001', 42)->andReturn([$ship('1001', 'new@example.com')]);
        $client->shouldReceive('findByOrderNumber')->with('1002', 42)->andReturn([$ship('1002', 'old@example.com')]);
        $client->shouldReceive('findByOrderNumber')->with('1003', 42)->andReturn([$ship('1003', 'broken@example.com')]);
        $client->shouldReceive('findByOrderNumber')->with('1004', 42)->andReturn([]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $shopify = Mockery::mock(ShopifyCustomers::class);
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'new@example.com')->twice()->andReturn(['orders' => [$this->order('#1001', '2026-10-01T00:00:00Z')], 'pages' => 1, 'truncated' => false]);
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'old@example.com')->twice()->andReturn(['orders' => [$this->order('#1002', '2026-10-02T00:00:00Z'), $this->order('#900', '2026-09-01T00:00:00Z', ['skus' => ['tee']])], 'pages' => 1, 'truncated' => false]);
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'broken@example.com')->twice()->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyCustomers::class, $shopify);

        $all = $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1]))->assertOk();
        $all->assertSeeText('First order')->assertSeeText('Repeat customer')->assertSeeText('Shopify history could not be loaded.')->assertSeeText('Not found in ShipStation:')->assertDontSeeText('secret');

        $repeat = $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'rule' => 'repeat']))->assertOk();
        $repeat->assertSeeText('old@example.com')->assertDontSeeText('new@example.com')->assertSeeText('1 / 3');
        $this->actingAs($operator)->get(route('print-queue.customer-context', ['rule' => 'bogus']))->assertSessionHasErrors('rule');
    }

    public function test_saved_filters_are_store_scoped_validated_and_drive_rule_vip_and_milestones(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $this->actingAs($operator)->post(route('print-queue.customer-context.filters.store'), ['name' => 'Bad', 'rule' => 'bogus', 'vip' => 5, 'milestones' => '3'])->assertSessionHasErrors('rule');
        $this->actingAs($operator)->post(route('print-queue.customer-context.filters.store'), ['name' => 'Bad', 'rule' => 'all', 'vip' => 5, 'milestones' => '1, x'])->assertSessionHasErrors(['milestones.0', 'milestones.1']);
        $this->actingAs($operator)->post(route('print-queue.customer-context.filters.store'), ['name' => 'Loyal', 'rule' => 'milestone', 'vip' => 8, 'milestones' => '3, 2, 3'])->assertRedirect();
        $filter = $store->customerContextFilters()->sole();
        $this->assertSame([2, 3], $filter->milestones);
        $this->assertSame(8, $filter->vip_at);

        $foreign = CustomerContextFilter::factory()->create();
        $this->actingAs($operator)->delete(route('print-queue.customer-context.filters.destroy', $foreign->id))->assertNotFound();
        $this->actingAs($operator)->get(route('print-queue.customer-context', ['filter' => $foreign->id]))->assertOk()->assertDontSee($foreign->name);

        $store->printQueueItems()->create(['order_number' => '1003']);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->andReturn([['orderId' => 3, 'orderNumber' => '1003', 'customerEmail' => 'm@example.com', 'advancedOptions' => ['storeId' => 42], 'items' => []]]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $shopify = Mockery::mock(ShopifyCustomers::class);
        $shopify->shouldReceive('customerFulfillmentHistory')->andReturn(['orders' => [$this->order('#1003', '2026-10-03T00:00:00Z'), $this->order('#1002', '2026-10-02T00:00:00Z'), $this->order('#1001', '2026-10-01T00:00:00Z')], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyCustomers::class, $shopify);

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['filter' => $filter->id, 'generate' => 1]))->assertOk()->assertSeeText('Milestone')->assertSeeText('1 / 1');
        $this->actingAs($operator)->get(route('print-queue.customer-context', ['filter' => $filter->id, 'generate' => 1, 'vip' => 3]))->assertOk()->assertSeeText('VIP');
        $this->actingAs($operator)->delete(route('print-queue.customer-context.filters.destroy', $filter->id))->assertRedirect();
        $this->assertSame(0, $store->customerContextFilters()->count());
    }

    public function test_packing_slip_and_pick_list_can_include_customer_context_and_survive_its_failure(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $store->printQueueItems()->create(['order_number' => '1002']);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->andReturn([['orderId' => 2, 'orderNumber' => '1002', 'customerEmail' => 'r@example.com', 'advancedOptions' => ['storeId' => 42], 'orderDate' => '2026-10-02T00:00:00', 'shipTo' => ['name' => 'R'], 'items' => [['sku' => 'TEE', 'name' => 'Tee', 'quantity' => 1]]]]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $shopify = Mockery::mock(ShopifyCustomers::class);
        $shopify->shouldReceive('customerFulfillmentHistory')->andReturn(['orders' => [$this->order('#1002', '2026-10-02T00:00:00Z'), $this->order('#1001', '2026-10-01T00:00:00Z', ['skus' => ['tee']])], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyCustomers::class, $shopify);

        $this->actingAs($operator)->post(route('orders.packing-slip.store'), ['order_number' => '1002', 'customer_context' => '1'])->assertOk()->assertSeeText('Repeat customer')->assertSeeText('tee');
        $this->actingAs($operator)->post(route('orders.packing-slip.store'), ['order_number' => '1002'])->assertOk()->assertDontSeeText('Repeat customer');
        $this->actingAs($operator)->get(route('print-queue.pick-list', ['generate' => 1, 'customer_context' => 1]))->assertOk()->assertSeeText('1002 — Repeat customer');
        $this->actingAs($operator)->get(route('print-queue.pick-list', ['generate' => 1]))->assertOk()->assertDontSeeText('Repeat customer');
    }

    public function test_open_orders_beyond_the_print_queue_are_capped_read_only_and_never_guessed_as_first(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $store->printQueueItems()->create(['order_number' => '9999']);
        $open = [];
        foreach (range(1, 52) as $i) {
            $open[] = ['orderId' => $i, 'orderNumber' => (string) (2000 + $i), 'advancedOptions' => ['storeId' => 42]];
        }
        $open[] = ['orderId' => 99, 'orderNumber' => '2001', 'advancedOptions' => ['storeId' => 42]];
        $ship = fn (string $number): array => ['orderId' => (int) $number, 'orderNumber' => $number, 'customerEmail' => 'c'.$number.'@example.com', 'advancedOptions' => ['storeId' => 42], 'items' => [['sku' => 'TEE', 'quantity' => 1]]];
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchBacklogOrders')->once()->with(42)->andReturn($open);
        $client->shouldReceive('findByOrderNumber')->times(50)->andReturnUsing(fn (string $number): array => [$ship($number)]);
        $client->shouldNotReceive('updateOrder', 'holdOrder', 'restoreOrder', 'createOrder', 'addOrderTag');
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $shopify = Mockery::mock(ShopifyCustomers::class);
        $shopify->shouldReceive('customerFulfillmentHistory')->andReturn(['orders' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyCustomers::class, $shopify);

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open', 'vip' => 5]))->assertOk()
            ->assertSeeText('Showing the oldest 50 of 52 open orders; the rest were not checked.')
            ->assertSeeText('Customer limit reached; not checked:')
            ->assertDontSeeText('9999');
    }

    public function test_open_order_with_truncated_or_failed_history_is_unknown_not_first(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $ship = fn (string $number, string $email): array => ['orderId' => (int) $number, 'orderNumber' => $number, 'customerEmail' => $email, 'advancedOptions' => ['storeId' => 42], 'items' => []];
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchBacklogOrders')->andReturn([$ship('3001', 'cut@example.com'), $ship('3002', 'down@example.com')]);
        $client->shouldReceive('findByOrderNumber')->with('3001', 42)->andReturn([$ship('3001', 'cut@example.com')]);
        $client->shouldReceive('findByOrderNumber')->with('3002', 42)->andReturn([$ship('3002', 'down@example.com')]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $shopify = Mockery::mock(ShopifyCustomers::class);
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'cut@example.com')->andReturn(['orders' => [$this->order('#3001', '2026-10-01T00:00:00Z')], 'pages' => 5, 'truncated' => true]);
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'down@example.com')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyCustomers::class, $shopify);

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open']))->assertOk()
            ->assertSeeText('Customer history was cut off')->assertSeeText('Shopify history could not be loaded.')->assertDontSeeText('secret');
        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open', 'rule' => 'first']))->assertOk()->assertSeeText('0 / 2');
        $this->actingAs($operator)->get(route('print-queue.customer-context', ['source' => 'bogus']))->assertSessionHasErrors('source');
    }

    public function test_open_source_reports_unconfigured_shipstation_and_empty_backlog(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchBacklogOrders')->andReturn([]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client, $client, null);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->app->instance(ShopifyCustomers::class, Mockery::mock(ShopifyCustomers::class));

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open']))->assertOk()->assertSeeText('No open orders found in ShipStation.');
        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open']))->assertOk()->assertSeeText('ShipStation is not configured completely for this store.');
    }
}
