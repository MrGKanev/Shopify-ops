<?php

namespace Tests\Feature;

use App\Application\Operations\RaiseOperationalIssue;
use App\Application\Orders\RecordPush;
use App\Integrations\ShipStation\ShipStationClient;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Jobs\ExecuteOrderRemediation;
use App\Jobs\PrepareOrderRemediation;
use App\Models\OperationalIssue;
use App\Models\RemediationRun;
use App\Models\Store;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderRemediationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preview_and_confirmation_are_operator_only_and_validate_input(): void
    {
        $this->get(route('orders.remediation.create'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('orders.remediation.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('orders.remediation.preview'), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('orders.remediation.store'), [])->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'bad', 'order_numbers' => ['../../bad']])->assertSessionHasErrors(['action', 'order_numbers.0']);
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'hold', 'order_numbers' => ['1001'], 'hold_until' => now()->subDay()->toDateString()])->assertSessionHasErrors('hold_until');
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'add_tag', 'order_numbers' => ['1001']])->assertSessionHasErrors('tag');
        $this->assertDatabaseCount('remediation_runs', 0);
    }

    public function test_sync_preview_is_read_only_then_updates_the_existing_order_and_resolves_its_issue(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $ss['shipTo']['street1'] = 'Old Street';
        $ss['internalNotes'] = 'Keep warehouse notes';
        $ss['shipTo']['residential'] = true;
        $ss['items'][0]['weight'] = ['value' => 2, 'units' => 'ounces'];
        $this->fakeIntegrations($store, $order, $ss);
        $issue = OperationalIssue::factory()->for($store)->create(['source_tool' => 'order_changed_after_push', 'fingerprint' => RaiseOperationalIssue::fingerprint('order_changed_after_push', '1'), 'reference' => '1']);

        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'sync_shipstation', 'order_numbers' => '#1001'])->assertRedirect();
        $run = $store->remediationRuns()->sole();
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertSame('draft', $run->status);
        $this->assertSame('Old Street', $run->plan['diff']['shipTo.street1']['shipstation']);
        $this->assertStringNotContainsString('Old Street', DB::table('remediation_runs')->value('plan'));
        $this->get(route('orders.remediation.show', $run->group_uuid))->assertSeeText('Old Street')->assertSeeText('Apply reviewed changes');

        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1'])->assertSessionHas('status', 'Remediation queued.');

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('resolved', $issue->fresh()->status->value);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders/createorder') && $request['orderId'] === 77 && $request['orderKey'] === 'gid://shopify/Order/1' && $request['orderStatus'] === 'awaiting_shipment' && $request['internalNotes'] === 'Keep warehouse notes' && $request['shipTo']['street1'] === '10 Main Street' && $request['shipTo']['residential'] === true && $request['items'][0]['weight']['value'] === 2);
        $this->assertDatabaseHas('activity_log', ['description' => 'remediation_completed', 'subject_id' => $run->id]);
        $this->get(route('jobs.index'))->assertSeeText('Order remediation')->assertSeeText('View changes and results');
    }

    public function test_changed_data_after_preview_blocks_the_job_without_writes(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $ss['shipTo']['street1'] = 'Old';
        $this->fakeIntegrations($store, $order, $ss);
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'sync_shipstation', 'order_numbers' => ['1001']]);
        $run = $store->remediationRuns()->sole();
        $order['shipping_address']['address1'] = 'Changed after preview';

        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringContainsString('changed since the preview', $run->fresh()->result_message);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_shipped_orders_and_ambiguous_matches_cannot_be_updated(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $ss['orderStatus'] = 'shipped';
        $this->fakeIntegrations($store, $order, $ss);

        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'sync_shipstation', 'order_numbers' => ['1001']]);

        $this->assertSame('blocked', $store->remediationRuns()->sole()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_another_store_or_operator_cannot_confirm_a_preview_and_expired_previews_are_rejected(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $run = RemediationRun::factory()->for($store)->for($operator, 'user')->create();
        [$otherOperator] = $this->userWithStore(true);
        $this->actingAs($otherOperator)->get(route('orders.remediation.show', $run->group_uuid))->assertNotFound();
        $this->actingAs($otherOperator)->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1'])->assertNotFound();
        $otherOperator->stores()->attach($store);
        $otherOperator->update(['active_store_id' => $store->id]);
        $this->actingAs($otherOperator)->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1'])->assertNotFound();
        $run->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($operator)->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1'])->assertConflict();
        $this->assertSame('draft', $run->fresh()->status);
    }

    public function test_double_confirmation_dispatches_one_batch_and_does_not_replay_a_completed_job(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $run = RemediationRun::factory()->for($store)->for($operator, 'user')->create();
        Bus::fake();

        $this->actingAs($operator)->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);
        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);

        Bus::assertBatchCount(1);
        $this->assertSame('queued', $run->fresh()->status);
        $run->update(['status' => 'completed']);
        Http::preventStrayRequests();
        app()->call([new ExecuteOrderRemediation($run->id), 'handle']);
        Http::assertNothingSent();
    }

    public function test_revoked_store_access_blocks_a_queued_job(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $run = RemediationRun::factory()->for($store)->for($operator, 'user')->create(['status' => 'queued']);
        $operator->stores()->detach($store);
        Http::preventStrayRequests();

        app()->call([new ExecuteOrderRemediation($run->id), 'handle']);

        $this->assertSame('failed', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_tracking_fulfillment_uses_exact_shipped_quantities_and_sends_no_customer_notification(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $ss['orderStatus'] = 'shipped';
        $this->fakeIntegrations($store, $order, $ss);

        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'fulfill_tracking', 'order_numbers' => ['1001']]);
        $run = $store->remediationRuns()->sole();
        $this->assertSame('draft', $run->status);
        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);

        $this->assertSame('completed', $run->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'] ?? '', 'mutation CreateRemediationFulfillment') && $request['variables']['fulfillment']['notifyCustomer'] === false && $request['variables']['fulfillment']['trackingInfo']['number'] === 'TRACK1' && $request['variables']['fulfillment']['lineItemsByFulfillmentOrder'][0]['fulfillmentOrderLineItems'] === [['id' => 'gid://shopify/FulfillmentOrderLineItem/21', 'quantity' => 2]]);
    }

    public function test_partial_fulfillment_mismatched_quantities_and_voided_labels_are_blocked(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $ss['orderStatus'] = 'shipped';
        $order['fulfillments'] = [['id' => 90, 'status' => 'success']];
        $this->fakeIntegrations($store, $order, $ss);
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'fulfill_tracking', 'order_numbers' => ['1001']]);
        $run = $store->remediationRuns()->sole();
        $this->assertSame('blocked', $run->status);
        $this->assertStringContainsString('partial fulfillments', $run->result_message);
        $order['fulfillments'] = [];
        $ss['items'][0]['quantity'] = 1;
        $this->post(route('orders.remediation.preview'), ['action' => 'fulfill_tracking', 'order_numbers' => ['1001']]);
        $run = $store->remediationRuns()->latest('id')->first();
        $this->assertSame('blocked', $run->status);
        $this->assertStringContainsString('quantities', $run->result_message);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request['query'] ?? '', 'mutation'));
    }

    public function test_tracking_is_added_to_existing_fulfillment_instead_of_creating_another(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $ss['orderStatus'] = 'shipped';
        $order['fulfillment_status'] = 'fulfilled';
        $order['fulfillments'] = [['id' => 90, 'status' => 'success', 'tracking_number' => '', 'tracking_numbers' => [], 'line_items' => [['sku' => 'SKU1', 'quantity' => 2]]]];
        $this->fakeIntegrations($store, $order, $ss);

        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'update_tracking', 'order_numbers' => ['1001']]);
        $run = $store->remediationRuns()->sole();
        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);

        $this->assertSame('completed', $run->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'] ?? '', 'mutation UpdateRemediationTracking') && $request['variables']['id'] === 'gid://shopify/Fulfillment/90');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request['query'] ?? '', 'mutation CreateRemediationFulfillment'));
    }

    public function test_hold_covers_shopify_shipstation_and_tag_and_release_targets_only_owned_holds(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $this->fakeIntegrations($store, $order, $ss);
        $options = ['action' => 'hold', 'order_numbers' => ['1001'], 'hold_until' => now()->addDays(7)->toDateString()];
        $this->actingAs($operator)->post(route('orders.remediation.preview'), $options);
        $run = $store->remediationRuns()->sole();
        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(3, $run->fresh()->completed_steps);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'] ?? '', 'mutation HoldRemediationFulfillment') && $request['variables']['hold']['handle'] === 'shopify-ops-review');
        $this->post(route('orders.remediation.preview'), ['action' => 'release_hold', 'order_numbers' => ['1001']]);
        $release = $store->remediationRuns()->latest('id')->first();
        $this->assertSame('draft', $release->status);
        $this->post(route('orders.remediation.store'), ['group_uuid' => $release->group_uuid, 'confirmed' => '1']);
        $this->assertSame('completed', $release->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'] ?? '', 'mutation ReleaseRemediationHold') && $request['variables']['holdIds'] === ['gid://shopify/FulfillmentHold/33']);
    }

    public function test_cancel_preserves_order_identity_and_requires_a_shopify_conflict(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $this->fakeIntegrations($store, $order, $ss);
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'cancel_shipstation', 'order_numbers' => ['1001']]);
        $this->assertSame('blocked', $store->remediationRuns()->sole()->status);
        $order['cancelled_at'] = now()->toIso8601String();
        $this->post(route('orders.remediation.preview'), ['action' => 'cancel_shipstation', 'order_numbers' => ['1001']]);
        $run = $store->remediationRuns()->latest('id')->first();
        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);
        $this->assertSame('completed', $run->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders/createorder') && $request['orderKey'] === 'gid://shopify/Order/1' && $request['orderStatus'] === 'cancelled');
    }

    public function test_shopify_tag_actions_work_without_shipstation_and_surface_user_errors(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $store->update(['shipstation_api_key' => null, 'shipstation_api_secret' => null]);
        $this->fakeIntegrations($store, $order, $ss);
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'add_tag', 'tag' => 'review', 'order_numbers' => ['1001']]);
        $run = $store->remediationRuns()->sole();
        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->post(route('orders.remediation.preview'), ['action' => 'remove_tag', 'tag' => 'review', 'order_numbers' => ['1001']]);
        $run = $store->remediationRuns()->latest('id')->first();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://'.$store->shopify_store.'.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['tagsRemove' => ['node' => null, 'userErrors' => [['field' => ['id'], 'message' => 'Access denied secret-token']]]]])]);
        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringNotContainsString('secret-token', $run->fresh()->result_message);
        $this->assertSame(0, $run->fresh()->completed_steps);
    }

    public function test_import_refresh_precedes_missing_push_and_a_native_import_prevents_duplicate_creation(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $store->pushLogs()->delete();
        $ss = [];
        $this->fakeIntegrations($store, $order, $ss);
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'push_missing', 'order_numbers' => ['1001']]);
        $this->assertSame('blocked', $store->remediationRuns()->sole()->status);
        $this->post(route('orders.remediation.preview'), ['action' => 'refresh_import', 'order_numbers' => ['1001']]);
        $refresh = $store->remediationRuns()->latest('id')->first();
        $this->post(route('orders.remediation.store'), ['group_uuid' => $refresh->group_uuid, 'confirmed' => '1']);
        $this->assertSame('completed', $refresh->fresh()->status);
        $this->post(route('orders.remediation.preview'), ['action' => 'push_missing', 'order_numbers' => ['1001']]);
        $push = $store->remediationRuns()->latest('id')->first();
        $this->assertSame('draft', $push->status);
        $ss = (new ShipStationClient('key', 'secret'))->buildOrderPayload($order);
        $ss['orderId'] = 77;
        $ss['advancedOptions'] = ['storeId' => (int) $store->store_number];
        $this->post(route('orders.remediation.store'), ['group_uuid' => $push->group_uuid, 'confirmed' => '1']);
        $this->assertSame('failed', $push->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders/createorder'));
    }

    public function test_orphan_tagging_uses_the_configured_shipstation_store_and_confirms_the_tag(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $this->fakeIntegrations($store, $order, $ss);
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'tag_shipstation', 'tag_id' => 5, 'order_numbers' => ['1001']]);
        $run = $store->remediationRuns()->sole();
        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);
        $this->assertSame('completed', $run->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders/addtag') && $request['tagId'] === 5 && $request['orderId'] === 77);
    }

    public function test_bulk_previews_are_queued_and_cannot_be_confirmed_until_all_are_ready(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $this->fakeIntegrations($store, $order, $ss);
        Bus::fake();
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'add_tag', 'tag' => 'review', 'order_numbers' => ['1001', '9999']])->assertRedirect();
        $runs = $store->remediationRuns()->orderBy('id')->get();
        $this->assertSame(['preparing', 'preparing'], $runs->pluck('status')->all());
        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 2 && $batch->jobs->every(fn ($job): bool => $job instanceof PrepareOrderRemediation));
        Http::assertNothingSent();
        $this->get(route('orders.remediation.show', $runs[0]->group_uuid))->assertSeeText('Preparing current order data')->assertDontSeeText('Apply reviewed changes');
        $this->post(route('orders.remediation.store'), ['group_uuid' => $runs[0]->group_uuid, 'confirmed' => '1'])->assertConflict();
        app()->call([new PrepareOrderRemediation($runs[0]->id), 'handle']);
        app()->call([new PrepareOrderRemediation($runs[1]->id), 'handle']);
        $this->assertSame('draft', $runs[0]->fresh()->status);
        $this->assertSame('blocked', $runs[1]->fresh()->status);
        $this->get(route('orders.remediation.show', $runs[0]->group_uuid))->assertSeeText('Apply reviewed changes')->assertSeeText('Exactly one matching Shopify order');
    }

    public function test_invalid_old_input_is_rendered_and_remote_order_text_is_escaped(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->from(route('orders.remediation.create'))->post(route('orders.remediation.preview'), ['action' => 'add_tag', 'order_numbers' => ['<script>']])->assertSessionHasErrors('order_numbers.0');
        $this->get(route('orders.remediation.create'))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
    }

    public function test_hold_failure_keeps_completed_steps_and_requires_a_new_preview(): void
    {
        [$operator, $store, $order, $ss] = $this->context();
        $this->fakeIntegrations($store, $order, $ss);
        $this->actingAs($operator)->post(route('orders.remediation.preview'), ['action' => 'hold', 'order_numbers' => ['1001'], 'hold_until' => now()->addDays(7)->toDateString()]);
        $run = $store->remediationRuns()->sole();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders/77' => Http::response($ss),
            'https://ssapi.shipstation.com/orders/holduntil' => Http::response(['success' => false]),
            'https://'.$store->shopify_store.'.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrder/2', 'status' => 'OPEN', 'supportedActions' => [['action' => 'HOLD'], ['action' => 'RELEASE_HOLD'], ['action' => 'CREATE_FULFILLMENT']], 'fulfillmentHolds' => [], 'lineItems' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrderLineItem/21', 'remainingQuantity' => 2, 'lineItem' => ['id' => 'gid://shopify/LineItem/11', 'sku' => 'SKU1']]], 'pageInfo' => ['hasNextPage' => false]]]], 'pageInfo' => ['hasNextPage' => false]]]]])
                ->push(['data' => ['fulfillmentOrderHold' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/2'], 'userErrors' => []]]]),
        ]);
        $this->post(route('orders.remediation.store'), ['group_uuid' => $run->group_uuid, 'confirmed' => '1']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->completed_steps);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request['query'] ?? '', 'mutation AddRemediationTags'));
        $this->get(route('orders.remediation.show', $run->group_uuid))->assertSeeText('1 / 3 steps confirmed')->assertDontSeeText('Apply reviewed changes');
    }

    /** @return array{User, Store, array<string, mixed>, array<string, mixed>} */
    private function context(): array
    {
        $this->freezeTime();
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $order = ['id' => 1, 'admin_graphql_api_id' => 'gid://shopify/Order/1', 'name' => '#1001', 'order_number' => '1001', 'created_at' => '2026-10-01', 'financial_status' => 'paid', 'fulfillment_status' => null, 'tags' => [], 'fulfillments' => [], 'shipping_address' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '10 Main Street', 'city' => 'Sofia', 'zip' => '1000', 'country_code' => 'BG'], 'line_items' => [['id' => 11, 'title' => 'Item', 'sku' => 'SKU1', 'quantity' => 2, 'price' => '10.00']], 'shipping_lines' => [['title' => 'Standard', 'price' => '5.00']]];
        $ss = (new ShipStationClient('key', 'secret'))->buildOrderPayload($order);
        $ss['orderId'] = 77;
        $ss['advancedOptions'] = ['storeId' => 12, 'warehouseId' => 9];
        app(RecordPush::class)->handle($store, '1001', '1', 77, $ss);

        return [$operator, $store, $order, $ss];
    }

    /** @param array<string, mixed> $order
     * @param array<string, mixed> $ss */
    private function fakeIntegrations(Store $store, array &$order, array &$ss): void
    {
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->andReturnUsing(function () use (&$order): array {
            return [$order];
        });
        Http::preventStrayRequests();
        $holds = [];
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => function (Request $request) use (&$ss): PromiseInterface {
                $path = parse_url($request->url(), PHP_URL_PATH);
                if ($request->method() === 'GET') {
                    return Http::response($path === '/orders' ? ['orders' => $ss === [] ? [] : [$ss]] : $ss);
                }
                if ($path === '/orders/createorder') {
                    $ss = $request->data();
                    $ss['orderId'] ??= 77;

                    return Http::response($ss);
                }
                if ($path === '/orders/holduntil') {
                    $ss['orderStatus'] = 'on_hold';
                    $ss['holdUntilDate'] = $request['holdUntilDate'];
                }
                if ($path === '/orders/restorefromhold') {
                    $ss['orderStatus'] = 'awaiting_shipment';
                }
                if ($path === '/orders/addtag') {
                    $ss['tagIds'][] = $request['tagId'];
                }

                return Http::response(['success' => true]);
            },
            'https://ssapi.shipstation.com/shipments*' => function () use (&$ss): PromiseInterface {
                return Http::response(['shipments' => [['shipmentId' => 44, 'orderId' => 77, 'trackingNumber' => 'TRACK1', 'voided' => false, 'shipmentItems' => $ss['items'] ?? []]]]);
            },
            'https://ssapi.shipstation.com/stores/refreshstore*' => Http::response(['success' => true]),
            'https://'.$store->shopify_store.'.myshopify.com/admin/api/2026-07/graphql.json' => function (Request $request) use (&$order, &$holds): PromiseInterface {
                $query = $request['query'];
                if (str_contains($query, 'query RemediationFulfillmentOrders')) {
                    return Http::response(['data' => ['order' => ['id' => 'gid://shopify/Order/1', 'fulfillmentOrders' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrder/2', 'status' => 'OPEN', 'supportedActions' => [['action' => 'HOLD'], ['action' => 'RELEASE_HOLD'], ['action' => 'CREATE_FULFILLMENT']], 'fulfillmentHolds' => $holds, 'lineItems' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrderLineItem/21', 'remainingQuantity' => 2, 'lineItem' => ['id' => 'gid://shopify/LineItem/11', 'sku' => 'SKU1']]], 'pageInfo' => ['hasNextPage' => false]]]], 'pageInfo' => ['hasNextPage' => false]]]]]);
                }
                $operations = ['HoldRemediationFulfillment' => ['fulfillmentOrderHold', 'fulfillmentOrder'], 'ReleaseRemediationHold' => ['fulfillmentOrderReleaseHold', 'fulfillmentOrder'], 'AddRemediationTags' => ['tagsAdd', 'node'], 'RemoveRemediationTags' => ['tagsRemove', 'node'], 'CreateRemediationFulfillment' => ['fulfillmentCreate', 'fulfillment'], 'UpdateRemediationTracking' => ['fulfillmentTrackingInfoUpdate', 'fulfillment']];
                foreach ($operations as $operation => [$field, $resource]) {
                    if (str_contains($query, 'mutation '.$operation)) {
                        if ($field === 'tagsAdd') {
                            $order['tags'] = array_values(array_unique([...$order['tags'], ...$request['variables']['tags']]));
                        }
                        if ($field === 'tagsRemove') {
                            $order['tags'] = array_values(array_diff($order['tags'], $request['variables']['tags']));
                        }
                        if ($field === 'fulfillmentOrderHold') {
                            $holds = [['id' => 'gid://shopify/FulfillmentHold/33', 'handle' => 'shopify-ops-review', 'heldByRequestingApp' => true], ['id' => 'gid://shopify/FulfillmentHold/34', 'handle' => 'another-app', 'heldByRequestingApp' => false]];
                        }
                        if ($field === 'fulfillmentOrderReleaseHold') {
                            $holds = [];
                        }

                        return Http::response(['data' => [$field => [$resource => ['id' => $resource === 'node' ? 'gid://shopify/Order/1' : ($resource === 'fulfillmentOrder' ? 'gid://shopify/FulfillmentOrder/2' : 'gid://shopify/Fulfillment/90')], 'userErrors' => []]]]);
                    }
                }

                return Http::response(['errors' => [['message' => 'Unexpected operation']]]);
            },
        ]);
    }
}
