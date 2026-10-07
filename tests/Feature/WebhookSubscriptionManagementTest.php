<?php

namespace Tests\Feature;

use App\Application\Health\ManageWebhookSubscriptions;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Models\Store;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class WebhookSubscriptionManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_creates_only_missing_topics_and_repeated_clicks_do_not_duplicate_them(): void
    {
        [$admin, $store] = $this->context();
        $subscriptions = [$this->subscription($store, 'ORDERS_CREATE', 1)];
        $this->fakeSubscriptions($store, $subscriptions);

        $this->actingAs($admin)->post(route('admin.webhook-health.register'))->assertSessionHas('status', 'Registered 6 missing webhooks.');
        $this->post(route('admin.webhook-health.register'))->assertSessionHas('status', 'Registered 0 missing webhooks.');

        Http::assertSentCount(8);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'], 'mutation CreateWebhookSubscription') && $request['variables']['topic'] === 'SHOPIFY_PAYMENTS_DISPUTE_CREATE' && $request['variables']['subscription']['uri'] === 'https://ops.example.test/webhooks/shopify/'.$store->slug);
        $this->assertSame(6, Activity::where('description', 'register_webhook')->count());
    }

    public function test_registration_requires_https_and_signing_secret_without_remote_writes(): void
    {
        [$admin, $store] = $this->context();
        config(['app.url' => 'http://localhost']);
        Http::preventStrayRequests();
        $this->actingAs($admin)->post(route('admin.webhook-health.register'))->assertSessionHasErrors('webhooks');
        config(['app.url' => 'https://ops.example.test']);
        $store->update(['shopify_webhook_secret' => null]);
        $this->post(route('admin.webhook-health.register'))->assertSessionHasErrors('webhooks');
        Http::assertNothingSent();
    }

    public function test_an_outdated_owned_subscription_can_be_removed_after_a_replacement_exists(): void
    {
        [$admin, $store] = $this->context();
        $old = $this->subscription($store, 'ORDERS_UPDATED', 1);
        $old['uri'] = 'https://old.example.test/webhooks/shopify/'.$store->slug;
        $subscriptions = [$old, $this->subscription($store, 'ORDERS_UPDATED', 2)];
        $this->fakeSubscriptions($store, $subscriptions);

        $this->actingAs($admin)->delete(route('admin.webhook-health.destroy'), ['subscription_id' => $old['id']])->assertSessionHas('status', 'Outdated webhook removed.');

        $this->assertCount(1, $subscriptions);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'], 'mutation DeleteWebhookSubscription') && $request['variables']['id'] === $old['id']);
        $this->assertDatabaseHas('activity_log', ['description' => 'remove_webhook', 'subject_id' => $store->id]);
    }

    public function test_foreign_subscriptions_and_required_topics_without_replacements_cannot_be_deleted(): void
    {
        [$admin, $store] = $this->context();
        $foreign = $this->subscription(Store::factory()->create(), 'ORDERS_UPDATED', 1);
        $old = $this->subscription($store, 'ORDERS_UPDATED', 2);
        $old['uri'] = 'https://old.example.test/webhooks/shopify/'.$store->slug;
        $subscriptions = [$foreign, $old];
        $this->fakeSubscriptions($store, $subscriptions);
        $this->actingAs($admin)->delete(route('admin.webhook-health.destroy'), ['subscription_id' => $foreign['id']])->assertSessionHasErrors('webhooks');
        $this->delete(route('admin.webhook-health.destroy'), ['subscription_id' => $old['id']])->assertSessionHasErrors('webhooks');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request['query'], 'mutation'));
        $this->assertCount(2, $subscriptions);
    }

    public function test_partial_registration_keeps_successful_topics_visible_and_does_not_leak_api_errors(): void
    {
        [$admin, $store] = $this->context();
        Http::preventStrayRequests();
        Http::fake(['https://'.$store->shopify_store.'.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
            ->push(['data' => ['webhookSubscriptions' => ['edges' => [], 'pageInfo' => ['hasNextPage' => false]]]])
            ->push(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'], 'userErrors' => []]]])
            ->push(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => null, 'userErrors' => [['field' => ['topic'], 'message' => 'secret-token']]]]])]);

        $this->actingAs($admin)->post(route('admin.webhook-health.register'))->assertSessionHasErrors('webhooks');

        $this->assertDatabaseHas('activity_log', ['description' => 'register_webhook']);
        $this->assertStringNotContainsString('secret-token', session('errors')->first('webhooks'));
        Http::assertSentCount(3);
    }

    public function test_operators_cannot_register_or_delete_and_invalid_ids_are_rejected(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('admin.webhook-health.register'))->assertForbidden();
        $this->delete(route('admin.webhook-health.destroy'), ['subscription_id' => 'gid://shopify/WebhookSubscription/1'])->assertForbidden();
        [$admin] = $this->context();
        $this->actingAs($admin)->delete(route('admin.webhook-health.destroy'), ['subscription_id' => '123'])->assertSessionHasErrors('subscription_id');
    }

    public function test_incomplete_pagination_and_filtered_subscriptions_do_not_count_as_complete_coverage(): void
    {
        [$admin, $store] = $this->context();
        $subscription = $this->subscription($store, 'ORDERS_UPDATED', 1);
        $subscription['includeFields'] = ['id'];
        $subscriptions = [$subscription];
        $this->fakeSubscriptions($store, $subscriptions);
        $this->assertContains('orders/updated', app(ManageWebhookSubscriptions::class)->missing(app(ManageWebhookSubscriptions::class)->subscriptions($store)));
        $this->mock(ShopifyTransport::class)->shouldReceive('paginateGraphql')->andReturn(['edges' => [], 'pages' => 20, 'truncated' => true]);
        $this->actingAs($admin)->post(route('admin.webhook-health.register'))->assertSessionHasErrors('webhooks');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request['query'], 'mutation'));
    }

    /** @return array{User, Store} */
    private function context(): array
    {
        config(['app.url' => 'https://ops.example.test']);
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create(['shopify_webhook_secret' => 'app-secret']);
        $admin->stores()->attach($store);

        return [$admin, $store];
    }

    /** @return array<string, mixed> */
    private function subscription(Store $store, string $topic, int $id): array
    {
        return ['id' => 'gid://shopify/WebhookSubscription/'.$id, 'topic' => $topic, 'uri' => 'https://ops.example.test/webhooks/shopify/'.$store->slug, 'name' => 'ShopifyOps:'.$store->id.':'.$topic, 'format' => 'JSON', 'createdAt' => '2026-10-01', 'apiVersion' => ['handle' => '2026-07'], 'filter' => null, 'includeFields' => []];
    }

    /** @param list<array<string, mixed>> $subscriptions */
    private function fakeSubscriptions(Store $store, array &$subscriptions): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://'.$store->shopify_store.'.myshopify.com/admin/api/2026-07/graphql.json' => function (Request $request) use ($store, &$subscriptions): PromiseInterface {
            $query = $request['query'];
            if (str_contains($query, 'query WebhookSubscriptions')) {
                return Http::response(['data' => ['webhookSubscriptions' => ['edges' => array_map(fn (array $subscription): array => ['node' => $subscription], $subscriptions), 'pageInfo' => ['hasNextPage' => false]]]]);
            }
            if (str_contains($query, 'mutation CreateWebhookSubscription')) {
                $subscription = $this->subscription($store, $request['variables']['topic'], count($subscriptions) + 1);
                $subscriptions[] = $subscription;

                return Http::response(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => $subscription['id']], 'userErrors' => []]]]);
            }
            $id = $request['variables']['id'];
            $subscriptions = array_values(array_filter($subscriptions, fn (array $subscription): bool => $subscription['id'] !== $id));

            return Http::response(['data' => ['webhookSubscriptionDelete' => ['deletedWebhookSubscriptionId' => $id, 'userErrors' => []]]]);
        }]);
    }
}
