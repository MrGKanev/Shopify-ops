<?php

namespace Tests\Feature;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationTrackingClient;
use App\Integrations\Shopify\ShopifyGraphqlTransport;
use App\Models\Store;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class IntegrationRedirectSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['shopify'])]
    #[TestWith(['shopify_graphql'])]
    #[TestWith(['tracking'])]
    public function test_integration_credentials_are_never_forwarded_to_redirect_destinations(string $integration): void
    {
        $store = Store::factory()->create(['store_number' => '42', 'shipstation_v2_api_key' => 'synthetic-v2-key']);
        Http::fake(function (Request $request, array $options): PromiseInterface {
            $this->assertFalse($options['allow_redirects']);

            return Http::response([], 302, ['Location' => 'https://untrusted.example/collect']);
        });
        try {
            if ($integration === 'shopify') {
                app(ShopifyGraphqlTransport::class)->get($store, 'shop.json');
            } elseif ($integration === 'shopify_graphql') {
                app(ShopifyGraphqlTransport::class)->graphql($store, '{ shop { name } }');
            } else {
                app(ShipStationTrackingClient::class)->track($store, 'usps', 'SYNTHETIC-TRACKING');
            }
            $this->fail('An integration redirect must be rejected.');
        } catch (UnexpectedResponse $exception) {
            $this->assertStringContainsString('redirect', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'untrusted.example'));
    }
}
