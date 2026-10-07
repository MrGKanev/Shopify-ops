<?php

namespace Tests\Feature;

use App\Application\Orders\RecordPush;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Jobs\ProcessShopifyWebhookEvent;
use App\Models\Store;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DetectOrderWebhookIssuesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_order_changed_after_successful_push_creates_one_review_issue(): void
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        app(RecordPush::class)->handle($store, '1001', '1001', 77);
        $this->travel(1)->minutes();
        $event = $this->orderEvent($store, '1001');
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->andReturn([$event->payload]);
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/orders/77' => Http::response(['orderId' => 77, 'orderKey' => 'gid://shopify/Order/1001', 'orderNumber' => '1001', 'orderStatus' => 'awaiting_shipment', 'shipTo' => ['street1' => 'OLD address'], 'items' => []])]);

        ProcessShopifyWebhookEvent::dispatchSync($event->id);
        ProcessShopifyWebhookEvent::dispatchSync($event->id);

        $this->assertDatabaseCount('operational_issues', 1);
        $this->assertDatabaseHas('operational_issues', ['store_id' => $store->id, 'source_tool' => 'order_changed_after_push', 'reference' => '1001', 'priority' => 'high', 'occurrences' => 1]);
    }

    public function test_old_changes_and_pushes_in_another_store_do_not_create_issues(): void
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();
        app(RecordPush::class)->handle($store, '1001', '1001', 77);
        app(RecordPush::class)->handle($otherStore, '1002', '1002', 78);
        $old = $this->orderEvent($store, '1001', ['updated_at' => now()->subMinute()->toIso8601String()]);
        $other = $this->orderEvent($store, '1002', ['updated_at' => now()->addMinute()->toIso8601String()]);

        ProcessShopifyWebhookEvent::dispatchSync($old->id);
        ProcessShopifyWebhookEvent::dispatchSync($other->id);

        $this->assertDatabaseCount('operational_issues', 0);
    }

    public function test_different_recipients_at_the_same_normalized_address_create_a_warning(): void
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        $first = $this->orderEvent($store, '1001');
        $second = $this->orderEvent($store, '1002', ['shipping_address' => ['name' => 'Maria Petrova', 'address1' => '  10 MAIN st. ', 'city' => 'SOFIA', 'country_code' => 'bg', 'zip' => '1000']]);

        ProcessShopifyWebhookEvent::dispatchSync($first->id);
        ProcessShopifyWebhookEvent::dispatchSync($second->id);

        $issue = $store->operationalIssues()->sole();
        $this->assertSame('repeated_shipping_address', $issue->source_tool);
        $this->assertSame(2, $issue->payload['order_count']);
        $this->assertTrue($issue->payload['warning_only']);
        $this->assertDatabaseCount('push_logs', 0);
    }

    public function test_three_orders_with_the_same_recipient_also_create_a_warning(): void
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        $this->orderEvent($store, '1001');
        $this->orderEvent($store, '1002');
        $third = $this->orderEvent($store, '1003');

        ProcessShopifyWebhookEvent::dispatchSync($third->id);

        $this->assertSame(3, $store->operationalIssues()->sole()->payload['order_count']);
    }

    public function test_repeated_events_fulfilled_cancelled_old_and_other_apartments_do_not_count(): void
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        $this->orderEvent($store, '1001');
        $current = $this->orderEvent($store, '1001');
        $this->orderEvent($store, '1002', ['fulfillment_status' => 'fulfilled']);
        $this->orderEvent($store, '1003', ['cancelled_at' => now()->toIso8601String()]);
        $this->orderEvent($store, '1004', ['created_at' => now()->subDays(8)->toIso8601String()]);
        $this->orderEvent($store, '1005', ['shipping_address' => ['name' => 'Maria', 'address1' => '10 Main St', 'address2' => 'Apartment 2', 'city' => 'Sofia', 'country_code' => 'BG', 'zip' => '1000']]);
        $this->orderEvent(Store::factory()->create(), '2001');

        ProcessShopifyWebhookEvent::dispatchSync($current->id);

        $this->assertDatabaseCount('operational_issues', 0);
    }

    public function test_latest_fulfillment_event_excludes_an_older_unfulfilled_snapshot(): void
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        $this->orderEvent($store, '1001');
        $this->orderEvent($store, '1002');
        $this->orderEvent($store, '1002', ['fulfillment_status' => 'fulfilled']);
        $third = $this->orderEvent($store, '1003');

        ProcessShopifyWebhookEvent::dispatchSync($third->id);

        $this->assertDatabaseCount('operational_issues', 0);
    }

    public function test_late_webhook_delivery_does_not_replace_a_newer_fulfilled_state(): void
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        $this->orderEvent($store, '1001');
        $this->orderEvent($store, '1002', ['fulfillment_status' => 'fulfilled']);
        $this->orderEvent($store, '1002', ['updated_at' => now()->subMinute()->toIso8601String()]);
        $third = $this->orderEvent($store, '1003');

        ProcessShopifyWebhookEvent::dispatchSync($third->id);

        $this->assertDatabaseCount('operational_issues', 0);
    }

    public function test_a_failed_push_does_not_create_a_change_after_push_issue(): void
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        app(RecordPush::class)->failed($store, '1001', '1001', new \RuntimeException('failed'));
        $this->travel(1)->minutes();
        $event = $this->orderEvent($store, '1001');

        ProcessShopifyWebhookEvent::dispatchSync($event->id);

        $this->assertDatabaseCount('operational_issues', 0);
    }

    public function test_same_recipient_with_different_emails_creates_only_a_review_warning(): void
    {
        $this->freezeTime();
        $store = Store::factory()->create();
        $this->orderEvent($store, '1001', ['email' => 'a@example.com']);
        $second = $this->orderEvent($store, '1002', ['email' => 'b@example.com']);

        ProcessShopifyWebhookEvent::dispatchSync($second->id);

        $issue = $store->operationalIssues()->sole();
        $this->assertSame('normal', $issue->priority->value);
        $this->assertSame(2, $issue->payload['different_emails']);
        $this->assertTrue($issue->payload['warning_only']);
        $this->assertDatabaseCount('push_logs', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function orderEvent(Store $store, string $id, array $overrides = []): WebhookEvent
    {
        return WebhookEvent::factory()->for($store)->create([
            'topic' => 'orders/updated', 'subject_id' => $id, 'occurred_at' => now(),
            'payload' => array_replace([
                'id' => $id, 'name' => '#'.$id, 'created_at' => now()->subHour()->toIso8601String(),
                'updated_at' => now()->toIso8601String(), 'fulfillment_status' => null,
                'shipping_address' => ['name' => 'Ivan Petrov', 'address1' => '10 Main St', 'city' => 'Sofia', 'country_code' => 'BG', 'zip' => '1000'],
            ], $overrides),
        ]);
    }
}
