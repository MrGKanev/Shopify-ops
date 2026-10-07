<?php

namespace Tests\Feature;

use App\Application\Orders\RecordPush;
use App\Integrations\ShipStation\ShipStationClient;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Jobs\ProcessShopifyWebhookEvent;
use App\Models\Store;
use App\Models\WebhookEvent;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostPushChangeDetectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_non_shipping_changes_and_already_synced_orders_do_not_raise_an_issue(): void
    {
        [$store, $order, $ss, $event] = $this->context();
        $order['tags'] = ['new-tag'];
        $order['note'] = 'Internal note changed';
        $ss['shipTo']['city'] = ' SOFIA ';
        $ss['items'] = array_reverse($ss['items']);
        $this->fakeCurrentState($order, $ss);

        ProcessShopifyWebhookEvent::dispatchSync($event->id);

        $this->assertDatabaseCount('operational_issues', 0);
        $this->assertSame('processed', $event->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_relevant_shipping_changes_raise_one_issue_with_a_live_diff_and_urgent_shipped_priority(): void
    {
        [$store, $order, $ss, $event] = $this->context();
        $order['line_items'][0]['quantity'] = 3;
        $order['shipping_address']['address2'] = 'Apartment 2';
        $ss['orderStatus'] = 'shipped';
        $this->fakeCurrentState($order, $ss);

        ProcessShopifyWebhookEvent::dispatchSync($event->id);
        ProcessShopifyWebhookEvent::dispatchSync($event->id);

        $issue = $store->operationalIssues()->sole();
        $this->assertSame('urgent', $issue->priority->value);
        $this->assertSame(1, $issue->occurrences);
        $this->assertSame('Apartment 2', $issue->payload['diff']['shipTo.street2']['shopify']);
        $this->assertSame(3, $issue->payload['diff']['items']['shopify'][0]['quantity']);
    }

    public function test_a_late_webhook_uses_current_state_and_resolves_an_issue_after_shipstation_catches_up(): void
    {
        [$store, $order, $ss, $event] = $this->context();
        $ss['shipTo']['street1'] = 'Old';
        $this->fakeCurrentState($order, $ss);
        ProcessShopifyWebhookEvent::dispatchSync($event->id);
        $this->assertSame('open', $store->operationalIssues()->sole()->status->value);
        $ss['shipTo']['street1'] = '10 Main Street';
        $second = WebhookEvent::factory()->for($store)->create(['topic' => 'orders/updated', 'subject_id' => '1', 'payload' => ['name' => '#1001', 'updated_at' => now()->toIso8601String(), 'shipping_address' => ['address1' => 'Old webhook data']]]);

        ProcessShopifyWebhookEvent::dispatchSync($second->id);

        $this->assertSame('resolved', $store->operationalIssues()->sole()->status->value);
    }

    public function test_an_unrelated_shipstation_order_identity_is_rejected_without_raising_a_false_issue(): void
    {
        [$store, $order, $ss, $event] = $this->context();
        $ss['orderKey'] = 'gid://shopify/Order/999';
        $this->fakeCurrentState($order, $ss);

        try {
            ProcessShopifyWebhookEvent::dispatchSync($event->id);
            $this->fail('A different ShipStation order must not be compared.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('identity', $exception->getMessage());
        }
        $this->assertDatabaseCount('operational_issues', 0);
    }

    public function test_a_push_snapshot_is_encrypted_and_removed_line_items_do_not_reappear(): void
    {
        [$store, $order] = $this->context();
        $snapshot = $store->pushLogs()->sole();
        $this->assertSame(64, mb_strlen($snapshot->payload_hash));
        $this->assertStringNotContainsString('10 main street', DB::table('push_logs')->value('payload_snapshot'));
        $this->assertSame('10 main street', $snapshot->payload_snapshot['shipTo']['street1']);
        $order['line_items'][0]['current_quantity'] = 0;
        $this->assertSame([], (new ShipStationClient('key', 'secret'))->buildOrderPayload($order)['items']);
    }

    /** @return array{Store, array<string, mixed>, array<string, mixed>, WebhookEvent} */
    private function context(): array
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        $order = ['id' => 1, 'name' => '#1001', 'order_number' => '1001', 'admin_graphql_api_id' => 'gid://shopify/Order/1', 'created_at' => now()->subDay()->toIso8601String(), 'shipping_address' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '10 Main Street', 'city' => 'Sofia', 'zip' => '1000', 'country_code' => 'BG'], 'line_items' => [['id' => 11, 'sku' => 'SKU1', 'quantity' => 2]], 'shipping_lines' => [['title' => 'Standard']]];
        $ss = (new ShipStationClient('key', 'secret'))->buildOrderPayload($order);
        $ss['orderId'] = 77;
        app(RecordPush::class)->handle($store, '1001', '1', 77, $ss);
        $this->travel(1)->minutes();
        $event = WebhookEvent::factory()->for($store)->create(['topic' => 'orders/updated', 'subject_id' => '1', 'payload' => ['name' => '#1001', 'updated_at' => now()->toIso8601String()]]);

        return [$store, $order, $ss, $event];
    }

    /** @param array<string, mixed> $order
     * @param array<string, mixed> $ss */
    private function fakeCurrentState(array &$order, array &$ss): void
    {
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->andReturnUsing(function () use (&$order): array {
            return [$order];
        });
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/orders/77' => function () use (&$ss): PromiseInterface {
            return Http::response($ss);
        }]);
    }
}
