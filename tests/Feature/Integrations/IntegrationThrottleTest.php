<?php

namespace Tests\Feature\Integrations;

use App\Domain\Orders\PhoneNumberValidator;
use App\Integrations\Exceptions\RateLimited;
use App\Integrations\IntegrationThrottle;
use App\Integrations\ShipStation\ShipStationClient;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyGraphqlTransport;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class IntegrationThrottleTest extends TestCase
{
    public function test_shipstation_clients_share_the_api_key_quota(): void
    {
        $this->freezeTime();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/orders*' => Http::response(['orders' => []])]);
        $first = new ShipStationClient('shared-key', 'secret', new PhoneNumberValidator);
        $second = new ShipStationClient('shared-key', 'secret', new PhoneNumberValidator);
        for ($request = 0; $request < 38; $request++) {
            ($request % 2 === 0 ? $first : $second)->healthCheck();
        }
        try {
            $second->healthCheck();
            $this->fail('The shared quota must prevent another outgoing request.');
        } catch (RateLimited $exception) {
            $this->assertSame(60, $exception->retryAfter);
        }
        Http::assertSentCount(38);
        Sleep::assertNeverSlept();
    }

    public function test_shipstation_keys_have_independent_quotas_and_expired_requests_leave_the_window(): void
    {
        $this->freezeTime();
        $throttle = new IntegrationThrottle;
        for ($request = 0; $request < 38; $request++) {
            $throttle->shipStation('first-key');
        }
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/orders*' => Http::response(['orders' => []])]);
        (new ShipStationClient('second-key', 'secret'))->healthCheck();
        $this->travel(60)->seconds();
        (new ShipStationClient('first-key', 'secret'))->healthCheck();
        Http::assertSentCount(2);
    }

    public function test_shipstation_waits_when_the_window_expires_within_the_block_timeout(): void
    {
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
        $throttle = new IntegrationThrottle;
        for ($request = 0; $request < 38; $request++) {
            $throttle->shipStation('key');
        }
        $this->travel(45)->seconds();
        $throttle->shipStation('key');
        Sleep::assertSequence([Sleep::for(15)->seconds()]);
    }

    public function test_http_retries_also_consume_shipstation_quota(): void
    {
        $this->freezeTime();
        Sleep::fake();
        $throttle = new IntegrationThrottle;
        for ($request = 0; $request < 37; $request++) {
            $throttle->shipStation('key');
        }
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/orders*' => Http::response([], 503)]);
        try {
            (new ShipStationClient('key', 'secret'))->healthCheck();
            $this->fail('A retry cannot bypass the quota.');
        } catch (RateLimited $exception) {
            $this->assertSame(429, $exception->status);
        }
        Http::assertSentCount(1);
    }

    public function test_shopify_budget_is_shared_by_transports_and_restores_over_time(): void
    {
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->response(100, 0, 50))]);
        $store = $this->store();
        (new ShopifyGraphqlTransport)->graphql($store, 'query { shop { name } }');
        (new ShopifyGraphqlTransport)->graphql($store, 'query { shop { name } }');
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
        Http::assertSentCount(2);
    }

    public function test_shopify_budget_does_not_delay_another_shop(): void
    {
        $this->freezeTime();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->response(100, 0, 50)),
            'https://other.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => []]),
        ]);
        $transport = new ShopifyGraphqlTransport;
        $transport->graphql($this->store(), 'query { shop { name } }');
        $transport->graphql(new Store(['shopify_store' => 'other', 'shopify_access_token' => 'token']), 'query { shop { name } }');
        Sleep::assertNeverSlept();
        Http::assertSentCount(2);
    }

    public function test_shopify_retries_throttled_http_200_using_the_cost_budget(): void
    {
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
        Http::preventStrayRequests();
        $throttled = $this->response(100, 0, 50);
        unset($throttled['data']);
        $throttled['errors'] = [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]];
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()->push($throttled)->push(['data' => ['shop' => ['name' => 'Acme']]])]);
        $result = (new ShopifyGraphqlTransport)->graphql($this->store(), 'query { shop { name } }');
        $this->assertSame('Acme', $result['data']['shop']['name']);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
        Http::assertSentCount(2);
    }

    public function test_shopify_stops_after_four_throttled_responses_without_a_budget(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['errors' => [['extensions' => ['code' => 'THROTTLED']]]])]);
        try {
            (new ShopifyGraphqlTransport)->graphql($this->store(), 'query { shop { name } }');
            $this->fail('Exhausted retries must surface rate limiting.');
        } catch (RateLimited $exception) {
            $this->assertSame(200, $exception->status);
        }
        Http::assertSentCount(4);
        Sleep::assertSequence([Sleep::for(100)->milliseconds(), Sleep::for(100)->milliseconds(), Sleep::for(100)->milliseconds()]);
    }

    public function test_other_graphql_errors_and_partial_data_are_never_retried(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['shop' => null], 'errors' => [['extensions' => ['code' => 'THROTTLED']]]])]);
        try {
            (new ShopifyGraphqlTransport)->graphql($this->store(), 'mutation { example }');
            $this->fail('Partial mutation execution must not be retried.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('THROTTLED', $exception->errors()[0]['extensions']['code']);
        }
        Http::assertSentCount(1);
    }

    public function test_zero_restore_rate_surfaces_a_safe_rate_limit_error(): void
    {
        $throttle = new IntegrationThrottle;
        $throttle->observeShopify('acme', 'query', $this->response(100, 0, 0));
        $this->expectException(RateLimited::class);
        $throttle->waitForShopify('acme', 'query');
    }

    public function test_budget_reserves_the_estimated_cost_when_a_response_has_no_cost_metadata(): void
    {
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
        $throttle = new IntegrationThrottle;
        $throttle->observeShopify('acme', 'query', $this->response(100, 100, 50));
        $this->assertFalse($throttle->waitForShopify('acme', 'query'));
        $this->assertTrue($throttle->waitForShopify('acme', 'query'));
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    public function test_elapsed_time_restores_the_budget_without_a_sleep(): void
    {
        $this->freezeTime();
        Sleep::fake();
        $throttle = new IntegrationThrottle;
        $throttle->observeShopify('acme', 'query', $this->response(100, 0, 50));
        $this->travel(2)->seconds();
        $this->assertFalse($throttle->waitForShopify('acme', 'query'));
        Sleep::assertNeverSlept();
    }

    public function test_shopify_does_not_sleep_longer_than_one_minute(): void
    {
        Sleep::fake();
        $throttle = new IntegrationThrottle;
        $throttle->observeShopify('acme', 'query', $this->response(100, 0, 1));
        try {
            $throttle->waitForShopify('acme', 'query');
            $this->fail('Excessive waits must be surfaced.');
        } catch (RateLimited $exception) {
            $this->assertSame(100, $exception->retryAfter);
        }
        Sleep::assertNeverSlept();
    }

    public function test_a_throttled_mutation_is_retried_only_when_it_has_no_partial_data(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
            ->push(['errors' => [['extensions' => ['code' => 'THROTTLED']]]])
            ->push(['data' => ['example' => ['id' => '1']]])]);
        $result = (new ShopifyGraphqlTransport)->graphql($this->store(), 'mutation { example }');
        $this->assertSame('1', $result['data']['example']['id']);
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(100)->milliseconds()]);
    }

    public function test_mixed_graphql_errors_are_not_retried(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['errors' => [
            ['extensions' => ['code' => 'THROTTLED']], ['extensions' => ['code' => 'ACCESS_DENIED']],
        ]])]);
        try {
            (new ShopifyGraphqlTransport)->graphql($this->store(), 'query { shop { name } }');
            $this->fail('Non-transient GraphQL errors must be surfaced.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertCount(2, $exception->errors());
        }
        Http::assertSentCount(1);
    }

    public function test_missing_or_malformed_cost_metadata_does_not_delay_a_request(): void
    {
        Sleep::fake();
        $throttle = new IntegrationThrottle;
        $throttle->observeShopify('acme', 'query', ['extensions' => ['cost' => ['throttleStatus' => ['restoreRate' => 'bad']]]]);
        $this->assertFalse($throttle->waitForShopify('acme', 'query'));
        Sleep::assertNeverSlept();
    }

    private function store(): Store
    {
        return new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']);
    }

    private function response(int $cost, int $available, int $rate): array
    {
        return ['data' => [], 'extensions' => ['cost' => ['requestedQueryCost' => $cost, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => $available, 'restoreRate' => $rate]]]];
    }
}
