<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessShopifyWebhookEvent;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookEventControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_admin_can_view_active_store_events_with_safe_output(): void
    {
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->operator()->create();
        $admin->stores()->attach($store);
        $operator->stores()->attach($store);
        WebhookEvent::factory()->for($store)->create(['topic' => '<script>alert(1)</script>']);
        WebhookEvent::factory()->for($otherStore)->create(['topic' => 'orders/hidden']);

        $this->get(route('admin.webhook-events'))->assertRedirect(route('login'));
        $this->actingAs($operator)->get(route('admin.webhook-events'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.webhook-events'))
            ->assertOk()
            ->assertSeeText('<script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSeeText('orders/hidden');
    }

    public function test_admin_can_filter_events_by_allowed_status_and_topic(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        WebhookEvent::factory()->for($store)->create(['topic' => 'orders/updated', 'status' => 'processed']);
        WebhookEvent::factory()->for($store)->create(['topic' => 'refunds/create', 'status' => 'received']);

        $this->actingAs($admin)->get(route('admin.webhook-events', ['topic' => 'orders', 'status' => 'processed']))
            ->assertOk()
            ->assertSeeText('orders/updated')
            ->assertDontSeeText('refunds/create');
        $this->actingAs($admin)->get(route('admin.webhook-events', ['status' => 'invalid']))->assertSessionHasErrors('status');
    }

    public function test_admin_can_retry_a_failed_webhook_from_the_active_store(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        $event = WebhookEvent::factory()->for($store)->create(['status' => 'failed', 'error_category' => 'RuntimeException']);
        Queue::fake([ProcessShopifyWebhookEvent::class]);

        $this->actingAs($admin)->post(route('admin.webhook-events.retry', $event))
            ->assertSessionHas('status', 'Webhook retry queued.');

        $this->assertDatabaseHas('webhook_events', ['id' => $event->getKey(), 'status' => 'received', 'error_category' => null]);
        Queue::assertPushed(ProcessShopifyWebhookEvent::class, fn (ProcessShopifyWebhookEvent $job): bool => $job->eventId === $event->getKey());
    }

    public function test_admin_cannot_retry_another_stores_event_or_a_non_failed_event(): void
    {
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        $otherEvent = WebhookEvent::factory()->for($otherStore)->create(['status' => 'failed']);
        $processedEvent = WebhookEvent::factory()->for($store)->create(['status' => 'processed']);
        Queue::fake([ProcessShopifyWebhookEvent::class]);

        $this->actingAs($admin)->post(route('admin.webhook-events.retry', $otherEvent))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.webhook-events.retry', $processedEvent))
            ->assertSessionHasErrors('webhook_event');

        Queue::assertNothingPushed();
    }
}
