<?php

namespace App\Application\Health;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyMutations;
use App\Integrations\Shopify\ShopifyQueries;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ManageWebhookSubscriptions
{
    public function __construct(private readonly ShopifyTransport $shopify, private readonly ShopifyMutations $mutations) {}

    public function callback(Store $store): string
    {
        return rtrim((string) config('app.url'), '/').route('webhooks.shopify', $store->slug, false);
    }

    /** @return list<array<string, mixed>> */
    public function subscriptions(Store $store): array
    {
        $result = $this->shopify->paginateGraphql($store, ShopifyQueries::get('WebhookSubscriptions'), 'webhookSubscriptions');
        if ($result['truncated']) {
            throw new RuntimeException('The webhook list is incomplete.');
        }
        $topics = array_flip(config('shopify-webhooks.topics'));
        $subscriptions = [];
        foreach ($result['edges'] as $edge) {
            $node = $edge['node'] ?? null;
            if (! is_array($node) || ! isset($node['id'], $node['topic'], $node['uri'], $node['format'])) {
                throw new RuntimeException('Shopify returned an unexpected webhook response.');
            }
            $topic = $topics[$node['topic']] ?? mb_strtolower(str_replace('_', '/', $node['topic']));
            $ours = str_starts_with((string) ($node['name'] ?? ''), 'ShopifyOps:'.$store->id.':') || $node['uri'] === $this->callback($store);
            $complete = ($node['filter'] ?? '') === '' || $node['filter'] === null;
            $complete = $complete && ($node['includeFields'] ?? []) === [];
            $expected = $node['uri'] === $this->callback($store) && isset($topics[$node['topic']]) && $node['format'] === 'JSON' && $complete;
            $subscriptions[] = [
                'id' => $node['id'], 'topic' => $topic, 'address' => $node['uri'], 'format' => $node['format'],
                'created_at' => $node['createdAt'] ?? '', 'api_version' => $node['apiVersion']['handle'] ?? '',
                'expected' => $expected,
                'healthy' => $expected && ($node['apiVersion']['handle'] ?? '') === ShopifyAdminClient::API_VERSION,
                'removable' => $ours && ! $expected,
            ];
        }

        return $subscriptions;
    }

    /** @param list<array<string, mixed>> $subscriptions
     * @return list<string> */
    public function missing(array $subscriptions): array
    {
        return array_values(array_diff(array_map(strval(...), array_keys(config('shopify-webhooks.topics'))), array_column(array_filter($subscriptions, fn (array $subscription): bool => $subscription['expected']), 'topic')));
    }

    public function registerMissing(Store $store): int
    {
        if ($store->missingShopifyCredentials() || trim((string) $store->shopify_webhook_secret) === '' || ! str_starts_with($this->callback($store), 'https://')) {
            throw new RuntimeException('Configure Shopify credentials, the app signing secret and an HTTPS APP_URL first.');
        }

        return Cache::lock('webhook-subscriptions:'.$store->id, 120)->block(5, function () use ($store): int {
            $missing = $this->missing($this->subscriptions($store));
            foreach ($missing as $topic) {
                $result = $this->mutations->handle($store, 'CreateWebhookSubscription', 'webhookSubscriptionCreate', [
                    'topic' => config('shopify-webhooks.topics')[$topic],
                    'subscription' => ['uri' => $this->callback($store), 'format' => 'JSON', 'name' => 'ShopifyOps:'.$store->id.':'.$topic],
                ]);
                if (empty($result['webhookSubscription']['id'])) {
                    throw new RuntimeException('Shopify did not confirm the webhook registration.');
                }
                activity('operator-actions')->performedOn($store)->withProperties(['topic' => $topic, 'subscription_id' => $result['webhookSubscription']['id']])->log('register_webhook');
            }

            return count($missing);
        });
    }

    public function remove(Store $store, string $id): void
    {
        Cache::lock('webhook-subscriptions:'.$store->id, 120)->block(5, function () use ($store, $id): void {
            $subscriptions = $this->subscriptions($store);
            $subscription = collect($subscriptions)->firstWhere('id', $id);
            if ($subscription === null || ! $subscription['removable']) {
                throw new RuntimeException('Only outdated subscriptions owned by this application can be removed.');
            }
            if (isset(config('shopify-webhooks.topics')[$subscription['topic']]) && in_array($subscription['topic'], $this->missing($subscriptions), true)) {
                throw new RuntimeException('Register the replacement webhook before removing this subscription.');
            }
            $result = $this->mutations->handle($store, 'DeleteWebhookSubscription', 'webhookSubscriptionDelete', ['id' => $id]);
            if (($result['deletedWebhookSubscriptionId'] ?? null) !== $id) {
                throw new RuntimeException('Shopify did not confirm the webhook deletion.');
            }
            activity('operator-actions')->performedOn($store)->withProperties(['subscription_id' => $id, 'topic' => $subscription['topic']])->log('remove_webhook');
        });
    }
}
