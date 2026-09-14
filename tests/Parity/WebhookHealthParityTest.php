<?php

declare(strict_types=1);

use App\Application\Health\CheckWebhookHealth;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\Store;
use PHPUnit\Framework\TestCase;

final class WebhookHealthParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_webhook_list_and_transport_success_match_legacy(): void
    {
        $webhooks = [[
            'id' => 42, 'topic' => 'orders/create', 'address' => 'https://example.com/hook', 'format' => 'json',
            'created_at' => '2026-06-01T10:00:00Z', 'api_version' => ShopifyAdminClient::API_VERSION,
        ]];
        $legacy = (new Shopify('acme.myshopify.com', 'token'))->fetchWebhooks(
            static fn (): array => ['ok' => true, 'code' => 200, 'error' => '', 'json' => ['webhooks' => $webhooks]],
        );
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('get')->once()->andReturn(['webhooks' => $webhooks]);
        $store = Mockery::mock(Store::class);
        $store->shouldReceive('getAttribute')->with('shopify_store')->andReturn('acme');
        $store->shouldReceive('getAttribute')->with('shopify_access_token')->andReturn('token');
        $laravel = (new CheckWebhookHealth($gateway))->handle($store);

        $this->assertSame('', $legacy['error']);
        $this->assertTrue($laravel['ok']);
        $this->assertSame(array_map('strval', array_column($legacy['webhooks'], 'id')), array_column($laravel['webhooks'], 'id'));
        $this->assertSame(array_column($legacy['webhooks'], 'topic'), array_column($laravel['webhooks'], 'topic'));
    }
}
