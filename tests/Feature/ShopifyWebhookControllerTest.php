<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ShopifyWebhookControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_signed_webhook_is_stored_once_with_encrypted_payload(): void
    {
        $store = Store::factory()->create(['slug' => 'acme', 'shopify_store' => 'acme', 'shopify_webhook_secret' => 'webhook-secret']);
        $body = json_encode(['id' => 1234, 'email' => 'private@example.com'], JSON_THROW_ON_ERROR);
        $headers = $this->headers($body, 'webhook-secret');

        $this->call('POST', route('webhooks.shopify', $store->slug), [], [], [], $headers, $body)->assertNoContent();
        $this->call('POST', route('webhooks.shopify', $store->slug), [], [], [], $headers, $body)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        $event = $store->webhookEvents()->sole();
        $this->assertSame('orders/updated', $event->topic);
        $this->assertSame('1234', $event->subject_id);
        $this->assertSame('private@example.com', $event->payload['email']);
        $this->assertStringNotContainsString('private@example.com', $event->getRawOriginal('payload'));
    }

    public function test_invalid_signature_wrong_shop_and_unconfigured_store_are_rejected(): void
    {
        $store = Store::factory()->create(['slug' => 'acme', 'shopify_store' => 'acme', 'shopify_webhook_secret' => 'correct-secret']);
        $body = json_encode(['id' => 1234], JSON_THROW_ON_ERROR);

        $this->call('POST', route('webhooks.shopify', $store->slug), [], [], [], $this->headers($body, 'wrong-secret'), $body)->assertUnauthorized();
        $this->call('POST', route('webhooks.shopify', $store->slug), [], [], [], [...$this->headers($body, 'correct-secret'), 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'other.myshopify.com'], $body)->assertUnauthorized();

        $store->update(['shopify_webhook_secret' => null]);
        $this->call('POST', route('webhooks.shopify', $store->slug), [], [], [], $this->headers($body, 'correct-secret'), $body)->assertStatus(503);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    /** @return array<string, string> */
    private function headers(string $body, string $secret): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'acme.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook-123',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/updated',
            'HTTP_X_SHOPIFY_API_VERSION' => '2026-07',
        ];
    }
}
