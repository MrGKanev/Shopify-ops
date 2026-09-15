<?php

namespace Tests\Feature;

use App\Jobs\ProcessShopifyWebhookEvent;
use App\Models\Store;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessShopifyWebhookEventTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_refund_and_dispute_events_create_prioritised_triage_issues(): void
    {
        $store = Store::factory()->create();
        $refund = WebhookEvent::factory()->for($store)->create(['topic' => 'refunds/create', 'subject_id' => '1001']);
        $dispute = WebhookEvent::factory()->for($store)->create(['topic' => 'disputes/create', 'subject_id' => '1002']);

        (new ProcessShopifyWebhookEvent($refund->getKey()))->handle();
        (new ProcessShopifyWebhookEvent($dispute->getKey()))->handle();

        $this->assertDatabaseHas('operational_issues', ['store_id' => $store->getKey(), 'reference' => '1001', 'priority' => 'high']);
        $this->assertDatabaseHas('operational_issues', ['store_id' => $store->getKey(), 'reference' => '1002', 'priority' => 'urgent']);
        $this->assertSame('processed', $refund->fresh()->status);
        $this->assertSame('processed', $dispute->fresh()->status);
    }

    public function test_other_events_are_recorded_as_processed_without_creating_an_issue(): void
    {
        $event = WebhookEvent::factory()->create(['topic' => 'orders/updated']);

        (new ProcessShopifyWebhookEvent($event->getKey()))->handle();

        $this->assertSame('processed', $event->fresh()->status);
        $this->assertDatabaseCount('operational_issues', 0);
    }

    public function test_terminal_failure_records_only_the_exception_category(): void
    {
        $event = WebhookEvent::factory()->create();

        (new ProcessShopifyWebhookEvent($event->getKey()))->failed(new RuntimeException('private details'));

        $this->assertDatabaseHas('webhook_events', ['id' => $event->getKey(), 'status' => 'failed', 'error_category' => RuntimeException::class]);
    }
}
