<?php

namespace Tests\Feature;

use App\Application\Operations\ManageShipStationMonitoring;
use App\Application\Operations\ReceiveShipStationEvent;
use App\Domain\Orders\ShipmentSyncAnalyzer;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClient;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\IssueStatus;
use App\Jobs\ProcessShipStationEvent;
use App\Jobs\ReconcileShipStationMonitoring;
use App\Models\ShipStationEvent;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ShipStationMonitoringTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_monitoring_is_off_by_default_and_catch_up_does_no_work(): void
    {
        $store = Store::factory()->create();
        Queue::fake();

        $this->artisan('shipstation:catch-up')->expectsOutput('Queued 0 opted-in store(s).')->assertSuccessful();

        $this->assertFalse($store->fresh()->shipstation_monitoring_enabled);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_administrator_can_enable_then_disable_and_pending_events_are_skipped(): void
    {
        $store = $this->store(false);
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        URL::forceScheme('https');
        Queue::fake();

        $this->actingAs($admin)->post(route('admin.stores.shipstation-monitoring', $store), ['enabled' => true])->assertRedirect();

        $store->refresh();
        $this->assertTrue($store->shipstation_monitoring_enabled);
        $this->assertSame(64, strlen($store->shipstation_monitoring_token));
        $event = app(ReceiveShipStationEvent::class)->handle($store, 'SHIP_NOTIFY', 'batch', ['resource_url' => $this->resource()]);
        $this->post(route('admin.stores.shipstation-monitoring', $store), ['enabled' => false])->assertRedirect();
        $this->assertFalse($store->fresh()->shipstation_monitoring_enabled);
        $this->assertSame('skipped', $event->fresh()->status);
        Queue::assertPushed(ReconcileShipStationMonitoring::class, 1);
        Http::assertNothingSent();
    }

    #[TestWith(['sync'])]
    #[TestWith(['null'])]
    public function test_enabling_requires_a_background_queue(string $driver): void
    {
        $store = $this->store(false);
        config(['queue.connections.database.driver' => $driver]);
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        URL::forceScheme('https');
        Queue::fake();

        $this->actingAs($admin)->post(route('admin.stores.shipstation-monitoring', $store), ['enabled' => true])->assertSessionHasErrors('shipstation_monitoring');

        $this->assertFalse($store->fresh()->shipstation_monitoring_enabled);
        Queue::assertNothingPushed();
    }

    #[TestWith(['shipstation_api_key', ''])]
    #[TestWith(['shopify_access_token', ''])]
    #[TestWith(['store_number', 'not-a-number'])]
    public function test_enabling_rejects_missing_credentials_or_invalid_store_mapping(string $field, string $value): void
    {
        $store = $this->store(false);
        $store->update([$field => $value]);
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        URL::forceScheme('https');
        Queue::fake();

        $this->actingAs($admin)->post(route('admin.stores.shipstation-monitoring', $store), ['enabled' => true])->assertSessionHasErrors('shipstation_monitoring');

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_enabling_rejects_an_http_webhook_url(): void
    {
        $store = $this->store(false);
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        URL::forceScheme('http');
        Queue::fake();

        $this->actingAs($admin)->post(route('admin.stores.shipstation-monitoring', $store), ['enabled' => true])->assertSessionHasErrors('shipstation_monitoring');

        Queue::assertNothingPushed();
    }

    public function test_guests_and_operators_cannot_toggle_monitoring(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        Queue::fake();

        $this->post(route('admin.stores.shipstation-monitoring', $store), ['enabled' => true])->assertRedirect(route('login'));
        $this->actingAs($operator)->post(route('admin.stores.shipstation-monitoring', $store), ['enabled' => true])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_callback_requires_opt_in_and_the_exact_secret(): void
    {
        $store = $this->store(false);
        Queue::fake();
        $payload = ['resource_type' => 'SHIP_NOTIFY', 'resource_url' => $this->resource()];

        $this->postJson($this->webhookUrl($store), $payload)->assertNotFound();
        $store->forceFill(['shipstation_monitoring_enabled' => true, 'shipstation_monitoring_token' => 'token'])->save();
        $this->postJson(route('webhooks.shipstation', ['store' => $store, 'token' => 'wrong']), $payload)->assertNotFound();

        $this->assertDatabaseCount('ship_station_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_callbacks_are_acknowledged_without_duplicate_work(): void
    {
        $store = $this->store();
        Queue::fake();
        $payload = ['resource_type' => 'SHIP_NOTIFY', 'resource_url' => $this->resource()];

        $this->postJson($this->webhookUrl($store), $payload)->assertNoContent();
        $this->postJson($this->webhookUrl($store), $payload)->assertNoContent();

        $this->assertDatabaseCount('ship_station_events', 1);
        Queue::assertPushed(ProcessShipStationEvent::class, 1);
        Http::assertNothingSent();
    }

    #[TestWith(['http://ssapi.shipstation.com/shipments?batchId=99'])]
    #[TestWith(['https://ssapi.shipstation.com.evil.test/shipments?batchId=99'])]
    #[TestWith(['https://user:pass@ssapi.shipstation.com/shipments?batchId=99'])]
    #[TestWith(['https://ssapi.shipstation.com:444/shipments?batchId=99'])]
    #[TestWith(['https://ssapi.shipstation.com/orders/createorder?batchId=99'])]
    #[TestWith(['https://ssapi.shipstation.com/shipments?storeID=13&batchId=99'])]
    #[TestWith(['https://ssapi.shipstation.com/shipments?storeID=12&storeId=13&batchId=99'])]
    #[TestWith(['https://ssapi.shipstation.com/shipments?batchId[]=99'])]
    #[TestWith(['https://ssapi.shipstation.com/shipments?batchId=99#fragment'])]
    #[TestWith(['https://api.shipstation.com/v2/shipments?batchId=99'])]
    public function test_untrusted_resource_urls_never_reach_the_api(string $url): void
    {
        $store = $this->store();
        Queue::fake();

        $this->postJson($this->webhookUrl($store), ['resource_type' => 'SHIP_NOTIFY', 'resource_url' => $url])->assertUnprocessable();

        $this->assertDatabaseCount('ship_station_events', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_callback_is_rate_limited(): void
    {
        $store = $this->store();
        Queue::fake();
        $payload = ['resource_type' => 'SHIP_NOTIFY', 'resource_url' => $this->resource()];
        for ($i = 0; $i < 120; $i++) {
            $this->postJson($this->webhookUrl($store), $payload)->assertNoContent();
        }

        $this->postJson($this->webhookUrl($store), $payload)->assertTooManyRequests();

        Queue::assertPushed(ProcessShipStationEvent::class, 1);
    }

    public function test_subscription_setup_is_store_scoped_and_recovers_an_existing_subscription(): void
    {
        $store = $this->store();
        URL::forceScheme('https');
        $url = $this->webhookUrl($store);
        Http::fake([
            'https://ssapi.shipstation.com/webhooks' => Http::response(['webhooks' => [['WebHookID' => 70, 'HookType' => 'SHIP_NOTIFY', 'Url' => $url]]]),
            'https://ssapi.shipstation.com/webhooks/subscribe' => Http::response(['WebHookID' => 71]),
        ]);

        app(ManageShipStationMonitoring::class)->handle($store);

        $this->assertSame(['SHIP_NOTIFY' => 70, 'ORDER_NOTIFY' => 71], $store->fresh()->shipstation_monitoring_subscriptions);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['store_id'] === 12 && $request['event'] === 'ORDER_NOTIFY' && $request['target_url'] === $url);
        $this->assertCount(2, Http::recorded());
    }

    public function test_disabled_store_only_removes_its_owned_subscriptions(): void
    {
        $store = $this->store(false);
        $store->forceFill(['shipstation_monitoring_token' => 'token', 'shipstation_monitoring_subscriptions' => ['SHIP_NOTIFY' => 70, 'ORDER_NOTIFY' => 71]])->save();
        Http::fake(['https://ssapi.shipstation.com/webhooks' => Http::response(['webhooks' => []]), 'https://ssapi.shipstation.com/webhooks/*' => Http::response(['success' => true])]);

        app()->call([new ReconcileShipStationMonitoring($store->id), 'handle']);

        $this->assertSame([], $store->fresh()->shipstation_monitoring_subscriptions);
        $this->assertSame('disabled', $store->fresh()->shipstation_monitoring_status);
        $this->assertCount(3, Http::recorded());
        Http::assertNotSent(fn (Request $request): bool => ! in_array($request->method(), ['GET', 'DELETE'], true));
    }

    public function test_resource_processing_queues_a_delayed_shipment_check_and_does_not_call_shopify(): void
    {
        $store = $this->store();
        Queue::fake();
        $event = app(ReceiveShipStationEvent::class)->handle($store, 'SHIP_NOTIFY', 'batch', ['resource_url' => $this->resource()]);
        $this->mock(ShopifyOrders::class)->shouldNotReceive('findByOrderNumber');
        Http::fake(['https://ssapi.shipstation.com/shipments*' => Http::response(['pages' => 1, 'shipments' => [$this->shipment()]])]);

        app()->call([new ProcessShipStationEvent($event->id), 'handle']);

        $check = $store->shipStationEvents()->where('topic', 'shipment_check')->sole();
        $this->assertTrue($check->available_at->gt(now()->addMinutes(14)));
        $this->assertSame('processed', $event->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request['storeId'] === 12 && $request['includeShipmentItems'] === 'true');
        Queue::assertPushed(ProcessShipStationEvent::class, 2);
    }

    public function test_store_is_checked_from_the_resource_not_trusted_from_the_subscription(): void
    {
        $store = $this->store();
        $event = $this->checkEvent($store);
        $this->mock(ShopifyOrders::class)->shouldNotReceive('findByOrderNumber');
        Http::fake(['https://ssapi.shipstation.com/orders/77' => Http::response([...$this->order(), 'advancedOptions' => ['storeId' => 13]])]);

        app()->call([new ProcessShipStationEvent($event->id), 'handle']);

        $this->assertDatabaseCount('operational_issues', 0);
        $this->assertSame('processed', $event->fresh()->status);
        $this->assertCount(1, Http::recorded());
    }

    public function test_shipment_checks_wait_then_raise_an_issue_without_writes_and_replays_are_safe(): void
    {
        $store = $this->store();
        Queue::fake();
        $event = app(ReceiveShipStationEvent::class)->handle($store, 'shipment_check', '88', ['order_id' => 77, 'shipment_id' => 88], now()->addMinutes(15));
        $this->fakeShipment();
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->once()->withArgs(fn (Store $selected, string $number): bool => $selected->is($store) && $number === '1001')->andReturn([$this->shopify()]);

        app()->call([new ProcessShipStationEvent($event->id), 'handle']);
        Http::assertNothingSent();
        $this->travel(16)->minutes();
        app()->call([new ProcessShipStationEvent($event->id), 'handle']);
        app()->call([new ProcessShipStationEvent($event->id), 'handle']);

        $issue = $store->operationalIssues()->sole();
        $this->assertSame('shipstation_sync', $issue->source_tool);
        $this->assertSame(88, $issue->payload['shipment_id']);
        $this->assertCount(2, $issue->payload['findings']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    #[TestWith(['disabled'])]
    #[TestWith(['new_generation'])]
    public function test_disabled_or_old_generation_jobs_do_not_call_integrations(string $case): void
    {
        $store = $this->store();
        $event = $this->checkEvent($store);
        $store->forceFill($case === 'disabled' ? ['shipstation_monitoring_enabled' => false] : ['shipstation_monitoring_token' => 'new-token'])->save();

        app()->call([new ProcessShipStationEvent($event->id), 'handle']);

        $this->assertSame('skipped', $event->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_partial_shipment_tracking_must_cover_its_specific_items_and_quantities(): void
    {
        $shopify = $this->shopify();
        $shopify['fulfillments'] = [['status' => 'success', 'tracking_number' => 'TRACK88', 'line_items' => [['id' => 21, 'quantity' => 1]]]];
        $analyzer = app(ShipmentSyncAnalyzer::class);

        $this->assertSame([], $analyzer->findings($this->order(), $this->shipment(), $shopify));
        $shopify['fulfillments'][0]['line_items'][0]['id'] = 22;
        $this->assertCount(2, $analyzer->findings($this->order(), $this->shipment(), $shopify));
        $shopify['fulfillments'][0]['line_items'][0] = ['id' => 21, 'quantity' => 0];
        $this->assertCount(2, $analyzer->findings($this->order(), $this->shipment(), $shopify));
        $shopify['fulfillments'][0]['line_items'][0]['quantity'] = 1;
        $shopify['fulfillments'][0]['tracking_number'] = 'OTHER-SHIPMENT';
        $this->assertSame(['Shopify tracking does not cover this shipment’s items and quantities.'], $analyzer->findings($this->order(), $this->shipment(), $shopify));
    }

    public function test_ambiguous_item_mapping_requires_review_instead_of_assuming_fulfillment(): void
    {
        $order = $this->order();
        unset($order['items'][0]['lineItemKey']);
        $shopify = $this->shopify();
        $shopify['line_items'][] = ['id' => 22, 'sku' => 'SKU1', 'quantity' => 1];

        $this->assertSame(['Shipment item mapping is incomplete or ambiguous; review the partial shipment manually.'], app(ShipmentSyncAnalyzer::class)->findings($order, $this->shipment(), $shopify));
    }

    public function test_catch_up_queues_missed_shipments_only_for_enabled_stores(): void
    {
        $store = $this->store();
        Store::factory()->create();
        Queue::fake();
        URL::forceScheme('https');
        Http::fake([
            'https://ssapi.shipstation.com/webhooks' => Http::response(['webhooks' => []]),
            'https://ssapi.shipstation.com/webhooks/subscribe' => Http::response(['WebHookID' => 70]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['pages' => 1, 'shipments' => [$this->shipment()]]),
        ]);

        $this->artisan('shipstation:catch-up')->assertSuccessful();
        Queue::assertPushed(ReconcileShipStationMonitoring::class, 1);
        app()->call([new ReconcileShipStationMonitoring($store->id), 'handle']);

        $this->assertNotNull($store->fresh()->shipstation_monitoring_checked_at);
        $this->assertDatabaseCount('ship_station_events', 1);
        Queue::assertPushed(ProcessShipStationEvent::class, 1);
    }

    public function test_client_does_not_follow_redirects_with_credentials(): void
    {
        Http::fake(['https://ssapi.shipstation.com/shipments*' => Http::response([], 302, ['Location' => 'https://evil.test/steal'])]);

        try {
            (new ShipStationClient('key', 'secret'))->webhookResource($this->resource(), 'SHIP_NOTIFY', 12);
            $this->fail('An unconfirmed resource must be rejected.');
        } catch (UnexpectedResponse) {
            $this->assertCount(1, Http::recorded());
        }
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.test'));
    }

    public function test_order_notifications_schedule_checks_only_for_shipped_orders_in_this_store(): void
    {
        $store = $this->store();
        Queue::fake();
        $url = 'https://ssapi.shipstation.com/orders?storeID=12&importBatch=batch-1';
        $event = app(ReceiveShipStationEvent::class)->handle($store, 'ORDER_NOTIFY', 'batch-1', ['resource_url' => $url]);
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::response(['pages' => 1, 'orders' => [
                [...$this->order(), 'orderStatus' => 'shipped'],
                [...$this->order(), 'orderStatus' => 'awaiting_shipment'],
                [...$this->order(), 'orderStatus' => 'shipped', 'advancedOptions' => ['storeId' => 13]],
            ]]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['pages' => 1, 'shipments' => [$this->shipment()]]),
        ]);

        app()->call([new ProcessShipStationEvent($event->id), 'handle']);

        $this->assertSame(1, $store->shipStationEvents()->where('topic', 'shipment_check')->count());
        $this->assertCount(2, Http::recorded());
        Queue::assertPushed(ProcessShipStationEvent::class, 2);
    }

    public function test_catch_up_rechecks_and_resolves_a_previously_missing_tracking_issue(): void
    {
        $store = $this->store();
        $event = $this->checkEvent($store);
        $this->fakeShipment();
        $shopify = $this->shopify();
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->andReturnUsing(fn (): array => [$shopify]);
        app()->call([new ProcessShipStationEvent($event->id), 'handle']);
        $issue = $store->operationalIssues()->sole();
        $this->assertSame(IssueStatus::Open, $issue->status);
        URL::forceScheme('https');
        Queue::fake();
        Http::fake([
            'https://ssapi.shipstation.com/webhooks' => Http::response(['webhooks' => []]),
            'https://ssapi.shipstation.com/webhooks/subscribe' => Http::response(['WebHookID' => 70]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['pages' => 1, 'shipments' => []]),
        ]);

        app()->call([new ReconcileShipStationMonitoring($store->id), 'handle']);

        $this->assertSame('received', $event->fresh()->status);
        Queue::assertPushed(ProcessShipStationEvent::class, 1);
        $shopify['fulfillments'] = [['status' => 'success', 'tracking_number' => 'TRACK88', 'line_items' => [['id' => 21, 'quantity' => 1]]]];
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->once()->andReturn([$shopify]);
        $this->fakeShipment();
        app()->call([new ProcessShipStationEvent($event->id), 'handle']);
        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
    }

    public function test_voided_shipments_do_not_create_a_missing_fulfillment_issue(): void
    {
        $store = $this->store();
        $event = $this->checkEvent($store);
        $this->mock(ShopifyOrders::class)->shouldNotReceive('findByOrderNumber');
        Http::fake([
            'https://ssapi.shipstation.com/orders/77' => Http::response($this->order()),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['pages' => 1, 'shipments' => [[...$this->shipment(), 'voided' => true]]]),
        ]);

        app()->call([new ProcessShipStationEvent($event->id), 'handle']);

        $this->assertDatabaseCount('operational_issues', 0);
        $this->assertSame('processed', $event->fresh()->status);
    }

    public function test_unconfirmed_pagination_does_not_advance_the_catch_up_watermark(): void
    {
        $store = $this->store();
        URL::forceScheme('https');
        Queue::fake();
        Http::fake([
            'https://ssapi.shipstation.com/webhooks' => Http::response(['webhooks' => []]),
            'https://ssapi.shipstation.com/webhooks/subscribe' => Http::response(['WebHookID' => 70]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => []]),
        ]);

        try {
            app()->call([new ReconcileShipStationMonitoring($store->id), 'handle']);
            $this->fail('Incomplete pagination must fail.');
        } catch (UnexpectedResponse) {
            $this->assertNull($store->fresh()->shipstation_monitoring_checked_at);
        }
        Queue::assertNothingPushed();
    }

    public function test_disabling_recovers_a_subscription_created_before_an_api_timeout(): void
    {
        $store = $this->store();
        $store->forceFill(['shipstation_monitoring_enabled' => false])->save();
        Http::fake([
            'https://ssapi.shipstation.com/webhooks' => Http::response(['webhooks' => [['WebHookID' => 70, 'HookType' => 'SHIP_NOTIFY', 'Url' => $this->webhookUrl($store)], ['WebHookID' => 99, 'HookType' => 'SHIP_NOTIFY', 'Url' => 'https://another-app.test/webhook']]]),
            'https://ssapi.shipstation.com/webhooks/70' => Http::response(['success' => true]),
        ]);

        app(ManageShipStationMonitoring::class)->handle($store);

        $this->assertNull($store->fresh()->shipstation_monitoring_token);
        $this->assertSame([], $store->fresh()->shipstation_monitoring_subscriptions);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/99'));
    }

    public function test_integration_identity_changes_are_blocked_until_monitoring_is_removed(): void
    {
        $store = $this->store();
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);

        $this->actingAs($admin)->put(route('admin.stores.update', $store), ['slug' => $store->slug, 'label' => $store->label, 'shopify_store' => $store->shopify_store, 'store_number' => '13', 'delivery_watch_days' => 7])->assertSessionHasErrors('store_number');

        $this->assertSame('12', $store->fresh()->store_number);
    }

    public function test_monitoring_settings_and_issues_render_in_bulgarian_without_exposing_secrets(): void
    {
        $store = $this->store();
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        $event = $this->checkEvent($store);
        $this->fakeShipment();
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->once()->andReturn([$this->shopify()]);
        app()->call([new ProcessShipStationEvent($event->id), 'handle']);

        app()->setLocale('bg');
        $this->actingAs($admin)->get(route('admin.stores.edit', $store))->assertOk()->assertSeeText('Следене на синхронизацията с ShipStation')->assertDontSee('value="token"', false);
        $this->get(route('operational-issues.index'))->assertOk()->assertSeeText('TRACK88')->assertSeeText('Shopify fulfillment не покрива изпратените артикули и количества.');
        $this->assertArrayNotHasKey('shipstation_monitoring_token', $store->toArray());
    }

    public function test_store_settings_render_the_subscription_removal_retry_action(): void
    {
        $store = $this->store(false);
        $store->forceFill(['shipstation_monitoring_token' => 'private-token', 'shipstation_monitoring_status' => 'failed'])->save();
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);

        $this->actingAs($admin)->get(route('admin.stores.edit', $store))->assertOk()->assertSeeText('Retry subscription removal')->assertDontSee('private-token');
    }

    private function store(bool $enabled = true): Store
    {
        config(['queue.default' => 'database']);
        $store = Store::factory()->create(['store_number' => '12']);
        $store->forceFill(['shipstation_monitoring_enabled' => $enabled, 'shipstation_monitoring_token' => $enabled ? 'token' : null, 'shipstation_monitoring_started_at' => now()])->save();

        return $store;
    }

    private function webhookUrl(Store $store): string
    {
        return route('webhooks.shipstation', ['store' => $store, 'token' => 'token']);
    }

    private function resource(): string
    {
        return 'https://ssapi.shipstation.com/shipments?storeID=12&batchId=99';
    }

    private function checkEvent(Store $store): ShipStationEvent
    {
        return ShipStationEvent::factory()->for($store)->create(['event_key' => hash('sha256', hash('sha256', 'token').'|shipment_check|88'), 'topic' => 'shipment_check', 'payload' => ['order_id' => 77, 'shipment_id' => 88]]);
    }

    private function fakeShipment(): void
    {
        Http::fake([
            'https://ssapi.shipstation.com/orders/77' => Http::response($this->order()),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['pages' => 1, 'shipments' => [$this->shipment()]]),
        ]);
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return ['orderId' => 77, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 12], 'items' => [['orderItemId' => 55, 'lineItemKey' => '21', 'sku' => 'SKU1', 'quantity' => 2]]];
    }

    /** @return array<string, mixed> */
    private function shipment(): array
    {
        return ['shipmentId' => 88, 'orderId' => 77, 'trackingNumber' => 'TRACK88', 'voided' => false, 'shipmentItems' => [['orderItemId' => 55, 'sku' => 'SKU1', 'quantity' => 1]]];
    }

    /** @return array<string, mixed> */
    private function shopify(): array
    {
        return ['id' => 1, 'line_items' => [['id' => 21, 'sku' => 'SKU1', 'quantity' => 2]], 'fulfillments' => []];
    }
}
