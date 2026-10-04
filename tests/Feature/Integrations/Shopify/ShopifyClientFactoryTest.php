<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyClientFactory;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class ShopifyClientFactoryTest extends TestCase
{
    public function test_interleaved_clients_keep_each_stores_host_and_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://alpha.myshopify.com/admin/api/2026-07/webhooks.json' => Http::response(['store' => 'alpha']),
            'https://beta.myshopify.com/admin/api/2026-07/webhooks.json' => Http::response(['store' => 'beta']),
        ]);
        $factory = app(ShopifyClientFactory::class);
        $alpha = $factory->forStore(new Store(['shopify_store' => 'alpha', 'shopify_access_token' => 'alpha-token']));
        $beta = $factory->forStore(new Store(['shopify_store' => 'beta', 'shopify_access_token' => 'beta-token']));

        $this->assertSame(['store' => 'alpha'], $alpha->get('webhooks.json'));
        $this->assertSame(['store' => 'beta'], $beta->get('webhooks.json'));
        $this->assertSame(['store' => 'alpha'], $alpha->get('webhooks.json'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'alpha.myshopify.com') && $request->hasHeader('X-Shopify-Access-Token', 'alpha-token'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'beta.myshopify.com') && $request->hasHeader('X-Shopify-Access-Token', 'beta-token'));
        Http::assertSentCount(3);
    }

    public function test_missing_credentials_fail_before_sending_a_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://alpha.myshopify.com/*' => Http::response([])]);
        $client = app(ShopifyClientFactory::class)->forStore(new Store(['shopify_store' => 'alpha']));

        try {
            $client->get('webhooks.json');
            $this->fail('Missing credentials must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The Shopify access token is missing.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
