<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\RateLimited;
use App\Integrations\Exceptions\Unauthorized;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\ShopifyClientFactory;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntegrationHttpConventionTest extends TestCase
{
    #[DataProvider('failureResponses')]
    public function test_clients_classify_http_failures_without_exposing_response_bodies(string $integration, int $status, string $exceptionClass, int $attempts): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $url = $integration === 'shopify' ? 'https://acme.myshopify.com/admin/api/2026-07/webhooks.json' : 'https://ssapi.shipstation.com/orders*';
        Http::fake([$url => Http::response(['error' => 'private token customer@example.com'], $status, ['Retry-After' => '40'])]);

        try {
            $this->read($integration);
            $this->fail('The HTTP failure must reach the caller.');
        } catch (IntegrationException $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
            $this->assertSame($status, $exception->status);
            $this->assertStringNotContainsString('private token', $exception->getMessage());
            if ($exception instanceof RateLimited) {
                $this->assertSame(40, $exception->retryAfter);
            }
        }

        Http::assertSentCount($attempts);
    }

    public static function failureResponses(): array
    {
        $cases = [];
        foreach (['shopify', 'shipstation'] as $integration) {
            foreach ([401 => [Unauthorized::class, 1], 403 => [Unauthorized::class, 1], 422 => [UnexpectedResponse::class, 1], 429 => [RateLimited::class, 4], 503 => [UnexpectedResponse::class, 4]] as $status => [$exception, $attempts]) {
                $cases[$integration.' '.$status] = [$integration, $status, $exception, $attempts];
            }
        }

        return $cases;
    }

    #[DataProvider('integrations')]
    public function test_clients_share_timeouts_user_agent_and_store_context(string $integration): void
    {
        Http::preventStrayRequests();
        Context::add('tool', 'convention_test');
        $options = [];
        $url = $integration === 'shopify' ? 'https://acme.myshopify.com/admin/api/2026-07/webhooks.json' : 'https://ssapi.shipstation.com/orders*';
        Http::fake([$url => function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(['orders' => [], 'webhooks' => []]);
        }]);

        $this->read($integration);

        $this->assertSame(3, $options['connect_timeout']);
        $this->assertSame(15, $options['timeout']);
        $this->assertSame(123, Context::get('store_id'));
        $this->assertSame('convention_test', Context::get('tool'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'ShopifyOps/2.0'));
    }

    public static function integrations(): array
    {
        return [['shopify'], ['shipstation']];
    }

    private function read(string $integration): array
    {
        $store = new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'shopify-token', 'shipstation_api_key' => 'ss-key', 'shipstation_api_secret' => 'ss-secret']);
        $store->id = 123;

        return $integration === 'shopify'
            ? app(ShopifyClientFactory::class)->forStore($store)->get('webhooks.json')
            : app(ShipStationClientFactory::class)->forStore($store)->findByOrderNumber('1001');
    }
}
