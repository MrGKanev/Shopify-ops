<?php

namespace Tests\Feature;

use App\Domain\Orders\CustomerFulfillmentContext;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyCustomers;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\CustomerContextFilter;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\Support\ShopifyFixtures;
use Tests\TestCase;

class CustomerContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_first_order_requires_complete_history(): void
    {
        $analyzer = new CustomerFulfillmentContext;
        $history = [ShopifyFixtures::historyOrder('#1002', '2026-10-02T10:00:00Z')];

        $first = $analyzer->classify('a@example.com', '1002', $history, false, ['TEE']);
        $this->assertSame('first', $first['status']);
        $this->assertContains('no_prior_orders', $first['reasons']);

        $truncated = $analyzer->classify('a@example.com', '1002', $history, true, ['TEE']);
        $this->assertSame('unknown', $truncated['status']);
        $this->assertSame(['history_truncated'], $truncated['reasons']);

        $this->assertSame('unknown', $analyzer->classify('a@example.com', '9999', $history, false, [])['status']);
        $this->assertSame('unknown', $analyzer->classify('', '1002', $history, false, [])['status']);
        $this->assertSame('unknown', $analyzer->classify('a@example.com', '1002', [ShopifyFixtures::historyOrder('#1002', 'not a date')], false, [])['status']);
    }

    public function test_repeat_counts_only_earlier_valid_orders_of_the_same_customer(): void
    {
        $history = [
            ShopifyFixtures::historyOrder('#1005', '2026-10-05T10:00:00Z'),
            ShopifyFixtures::historyOrder('#1004', '2026-10-04T10:00:00Z', ['skus' => ['tee', 'mug']]),
            ShopifyFixtures::historyOrder('#1003', '2026-10-03T10:00:00Z', ['cancelled' => true, 'skus' => ['hat']]),
            ShopifyFixtures::historyOrder('#1002', '2026-10-02T10:00:00Z', ['test' => true]),
            ShopifyFixtures::historyOrder('#1001', '2026-10-01T10:00:00Z', ['customer_id' => 'other']),
            ShopifyFixtures::historyOrder('#1006', '2026-10-06T10:00:00Z'),
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
        $history = [ShopifyFixtures::historyOrder('#5', '2026-10-05T10:00:00Z', ['lines_complete' => false]), ShopifyFixtures::historyOrder('#4', '2026-10-04T10:00:00Z'), ShopifyFixtures::historyOrder('#3', '2026-10-03T10:00:00Z'), ShopifyFixtures::historyOrder('#2', '2026-10-02T10:00:00Z'), ShopifyFixtures::historyOrder('#1', '2026-10-01T10:00:00Z')];
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

    public function test_guest_is_sent_to_login_for_the_customer_context_page(): void
    {
        $this->get(route('print-queue.customer-context'))->assertRedirect(route('login'));
    }

    public function test_viewer_cannot_open_the_customer_context_page(): void
    {
        [$viewer] = $this->userWithStore();

        $this->actingAs($viewer)->get(route('print-queue.customer-context'))->assertForbidden();
    }

    public function test_page_classifies_every_queued_order_and_reports_what_could_not_be_checked(): void
    {
        $operator = $this->queuedOrdersWithHistory();

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1]))->assertOk()
            ->assertSeeText('First order')->assertSeeText('Repeat customer')
            ->assertSeeText('Shopify history could not be loaded.')->assertSeeText('Not found in ShipStation:');
    }

    public function test_failed_history_lookup_never_exposes_the_upstream_error(): void
    {
        $operator = $this->queuedOrdersWithHistory();

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1]))->assertOk()->assertDontSeeText('secret');
    }

    public function test_rule_filter_keeps_only_matching_customers(): void
    {
        $operator = $this->queuedOrdersWithHistory();

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'rule' => 'repeat']))->assertOk()
            ->assertSeeText('old@example.com')->assertDontSeeText('new@example.com')->assertSeeText('1 / 3');
    }

    public function test_unknown_rule_is_rejected(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['rule' => 'bogus']))->assertSessionHasErrors('rule');
    }

    public function test_page_never_modifies_shipstation(): void
    {
        $operator = $this->queuedOrdersWithHistory($client);
        $client->shouldNotReceive('updateOrder', 'holdOrder', 'restoreOrder', 'createOrder', 'addOrderTag');

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1]))->assertOk();
    }

    public function test_saved_filter_rejects_an_unknown_rule(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);

        $this->actingAs($operator)->post(route('print-queue.customer-context.filters.store'), ['name' => 'Bad', 'rule' => 'bogus', 'vip' => 5, 'milestones' => '3'])->assertSessionHasErrors('rule');
    }

    public function test_saved_filter_rejects_non_numeric_milestones(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);

        $this->actingAs($operator)->post(route('print-queue.customer-context.filters.store'), ['name' => 'Bad', 'rule' => 'all', 'vip' => 5, 'milestones' => '1, x'])->assertSessionHasErrors(['milestones.0', 'milestones.1']);
    }

    public function test_saved_filter_is_stored_for_the_active_store_with_sorted_unique_milestones(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);

        $this->actingAs($operator)->post(route('print-queue.customer-context.filters.store'), ['name' => 'Loyal', 'rule' => 'milestone', 'vip' => 8, 'milestones' => '3, 2, 3'])->assertRedirect();

        $filter = $store->customerContextFilters()->sole();
        $this->assertSame([2, 3], $filter->milestones);
        $this->assertSame(8, $filter->vip_at);
    }

    public function test_another_stores_saved_filter_can_neither_be_deleted_nor_applied(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $foreign = CustomerContextFilter::factory()->create();

        $this->actingAs($operator)->delete(route('print-queue.customer-context.filters.destroy', $foreign->id))->assertNotFound();
        $this->actingAs($operator)->get(route('print-queue.customer-context', ['filter' => $foreign->id]))->assertOk()->assertDontSee($foreign->name);
        $this->assertModelExists($foreign);
    }

    public function test_saved_filter_milestones_and_vip_threshold_label_the_customer(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $filter = CustomerContextFilter::factory()->for($store)->create(['rule' => 'milestone', 'vip_at' => 8, 'milestones' => [2, 3]]);
        $this->threeOrderCustomerForOrder('1003', $store);

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['filter' => $filter->id, 'generate' => 1]))->assertOk()->assertSeeText('Milestone')->assertSeeText('1 / 1');
        $this->actingAs($operator)->get(route('print-queue.customer-context', ['filter' => $filter->id, 'generate' => 1, 'vip' => 3]))->assertOk()->assertSeeText('VIP');
    }

    public function test_saved_filter_can_be_deleted(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $filter = CustomerContextFilter::factory()->for($store)->create();

        $this->actingAs($operator)->delete(route('print-queue.customer-context.filters.destroy', $filter->id))->assertRedirect();

        $this->assertModelMissing($filter);
    }

    public function test_packing_slip_includes_customer_context_only_when_asked(): void
    {
        $operator = $this->repeatCustomerInPrintQueue();

        $this->actingAs($operator)->post(route('orders.packing-slip.store'), ['order_number' => '1002', 'customer_context' => '1'])->assertOk()->assertSeeText('Repeat customer')->assertSeeText('tee');
        $this->actingAs($operator)->post(route('orders.packing-slip.store'), ['order_number' => '1002'])->assertOk()->assertDontSeeText('Repeat customer');
    }

    public function test_pick_list_includes_customer_context_only_when_asked(): void
    {
        $operator = $this->repeatCustomerInPrintQueue();

        $this->actingAs($operator)->get(route('print-queue.pick-list', ['generate' => 1, 'customer_context' => 1]))->assertOk()->assertSeeText('1002 — Repeat customer');
        $this->actingAs($operator)->get(route('print-queue.pick-list', ['generate' => 1]))->assertOk()->assertDontSeeText('Repeat customer');
    }

    public function test_packing_slip_still_renders_when_customer_context_fails(): void
    {
        $operator = $this->repeatCustomerInPrintQueue();
        $this->failingShopifyHistory();

        $this->actingAs($operator)->post(route('orders.packing-slip.store'), ['order_number' => '1002', 'customer_context' => '1'])->assertOk()->assertSeeText('Packing Slip')->assertDontSeeText('Repeat customer')->assertDontSeeText('secret');
    }

    public function test_open_orders_beyond_the_cap_are_not_checked_and_the_print_queue_is_not_mixed_in(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $store->printQueueItems()->create(['order_number' => '9999']);
        $open = array_map(fn (int $i): array => ShipStationFixtures::order((string) (2000 + $i)), range(1, 52));
        $open[] = ShipStationFixtures::order('2001', ['orderId' => 99]);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchBacklogOrders')->with(42)->andReturn($open);
        $client->shouldReceive('findByOrderNumber')->andReturnUsing(fn (string $number): array => [ShipStationFixtures::order($number, ['customerEmail' => 'c'.$number.'@example.com', 'items' => [['sku' => 'TEE', 'quantity' => 1]]])]);
        $this->bindShopify(ShopifyFixtures::history([]));

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open', 'vip' => 5]))->assertOk()
            ->assertSeeText('Showing the oldest 50 of 52 open orders; the rest were not checked.')
            ->assertSeeText('Customer limit reached; not checked:')
            ->assertDontSeeText('9999');
    }

    public function test_open_order_with_truncated_or_failed_history_is_unknown_not_first(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->twoOpenOrdersWithUnreliableHistory();

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open']))->assertOk()
            ->assertSeeText('Customer history was cut off')->assertSeeText('Shopify history could not be loaded.')->assertDontSeeText('secret');
    }

    public function test_first_order_rule_excludes_open_orders_with_unreliable_history(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->twoOpenOrdersWithUnreliableHistory();

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open', 'rule' => 'first']))->assertOk()->assertSeeText('0 / 2');
    }

    public function test_unknown_source_is_rejected(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['source' => 'bogus']))->assertSessionHasErrors('source');
    }

    public function test_open_source_reports_an_empty_backlog(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        ShipStationFixtures::bindClient()->shouldReceive('fetchBacklogOrders')->andReturn([]);
        $this->app->instance(ShopifyCustomers::class, Mockery::mock(ShopifyCustomers::class));

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open']))->assertOk()->assertSeeText('No open orders found in ShipStation.');
    }

    public function test_open_source_reports_unconfigured_shipstation(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn(null);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->app->instance(ShopifyCustomers::class, Mockery::mock(ShopifyCustomers::class));

        $this->actingAs($operator)->get(route('print-queue.customer-context', ['generate' => 1, 'source' => 'open']))->assertOk()->assertSeeText('ShipStation is not configured completely for this store.');
    }

    /**
     * Four queued orders: a first-time customer, a repeat customer, a customer whose Shopify lookup fails, and one missing from ShipStation.
     */
    private function queuedOrdersWithHistory(?MockInterface &$client = null): User
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        foreach (['1001', '1002', '1003', '1004'] as $number) {
            $store->printQueueItems()->create(['order_number' => $number]);
        }
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('findByOrderNumber')->with('1001', 42)->andReturn([ShipStationFixtures::order('1001', ['customerEmail' => 'new@example.com', 'items' => [['sku' => 'TEE', 'quantity' => 1]]])]);
        $client->shouldReceive('findByOrderNumber')->with('1002', 42)->andReturn([ShipStationFixtures::order('1002', ['customerEmail' => 'old@example.com', 'items' => [['sku' => 'TEE', 'quantity' => 1]]])]);
        $client->shouldReceive('findByOrderNumber')->with('1003', 42)->andReturn([ShipStationFixtures::order('1003', ['customerEmail' => 'broken@example.com', 'items' => [['sku' => 'TEE', 'quantity' => 1]]])]);
        $client->shouldReceive('findByOrderNumber')->with('1004', 42)->andReturn([]);
        $shopify = Mockery::mock(ShopifyCustomers::class);
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'new@example.com')->andReturn(ShopifyFixtures::history([ShopifyFixtures::historyOrder('#1001', '2026-10-01T00:00:00Z')]));
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'old@example.com')->andReturn(ShopifyFixtures::history([ShopifyFixtures::historyOrder('#1002', '2026-10-02T00:00:00Z'), ShopifyFixtures::historyOrder('#900', '2026-09-01T00:00:00Z', ['skus' => ['tee']])]));
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'broken@example.com')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyCustomers::class, $shopify);

        return $operator;
    }

    private function repeatCustomerInPrintQueue(): User
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $store->printQueueItems()->create(['order_number' => '1002']);
        ShipStationFixtures::bindClient()->shouldReceive('findByOrderNumber')->andReturn([ShipStationFixtures::order('1002', ['customerEmail' => 'r@example.com', 'orderDate' => '2026-10-02T00:00:00', 'shipTo' => ['name' => 'R'], 'items' => [['sku' => 'TEE', 'name' => 'Tee', 'quantity' => 1]]])]);
        $this->bindShopify(ShopifyFixtures::history([ShopifyFixtures::historyOrder('#1002', '2026-10-02T00:00:00Z'), ShopifyFixtures::historyOrder('#1001', '2026-10-01T00:00:00Z', ['skus' => ['tee']])]));

        return $operator;
    }

    private function threeOrderCustomerForOrder(string $number, Store $store): void
    {
        $store->printQueueItems()->create(['order_number' => $number]);
        ShipStationFixtures::bindClient()->shouldReceive('findByOrderNumber')->andReturn([ShipStationFixtures::order($number, ['customerEmail' => 'm@example.com'])]);
        $this->bindShopify(ShopifyFixtures::history([ShopifyFixtures::historyOrder('#1003', '2026-10-03T00:00:00Z'), ShopifyFixtures::historyOrder('#1002', '2026-10-02T00:00:00Z'), ShopifyFixtures::historyOrder('#1001', '2026-10-01T00:00:00Z')]));
    }

    private function twoOpenOrdersWithUnreliableHistory(): void
    {
        $cut = ShipStationFixtures::order('3001', ['customerEmail' => 'cut@example.com']);
        $down = ShipStationFixtures::order('3002', ['customerEmail' => 'down@example.com']);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchBacklogOrders')->andReturn([$cut, $down]);
        $client->shouldReceive('findByOrderNumber')->with('3001', 42)->andReturn([$cut]);
        $client->shouldReceive('findByOrderNumber')->with('3002', 42)->andReturn([$down]);
        $shopify = Mockery::mock(ShopifyCustomers::class);
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'cut@example.com')->andReturn(ShopifyFixtures::history([ShopifyFixtures::historyOrder('#3001', '2026-10-01T00:00:00Z')], true, 5));
        $shopify->shouldReceive('customerFulfillmentHistory')->with(Mockery::type(Store::class), 'down@example.com')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyCustomers::class, $shopify);
    }

    /**
     * @param  array{orders: list<array<string, mixed>>, pages: int, truncated: bool}  $history
     */
    private function bindShopify(array $history): void
    {
        $shopify = Mockery::mock(ShopifyCustomers::class);
        $shopify->shouldReceive('customerFulfillmentHistory')->andReturn($history);
        $this->app->instance(ShopifyCustomers::class, $shopify);
    }

    private function failingShopifyHistory(): void
    {
        $shopify = Mockery::mock(ShopifyCustomers::class);
        $shopify->shouldReceive('customerFulfillmentHistory')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyCustomers::class, $shopify);
    }
}
