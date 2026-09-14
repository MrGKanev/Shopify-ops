<?php

namespace App\Application\Health;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\Store;
use Throwable;

class CheckWebhookHealth
{
    public function __construct(private readonly ShopifyAdminGateway $shopify) {}

    /** @return array{ok:bool,error:string,webhooks:list<array{id:string,topic:string,address:string,format:string,created_at:string,api_version:string,healthy:bool}>} */
    public function handle(Store $store): array
    {
        if (trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '') {
            return ['ok' => false, 'error' => 'Shopify credentials are incomplete.', 'webhooks' => []];
        }

        try {
            $payload = $this->shopify->get($store, 'webhooks.json', ['limit' => 250]);
            if (! is_array($payload['webhooks'] ?? null)) {
                return ['ok' => false, 'error' => 'Shopify returned an unexpected webhook response.', 'webhooks' => []];
            }

            $webhooks = [];
            foreach ($payload['webhooks'] as $webhook) {
                if (! is_array($webhook)) {
                    continue;
                }
                $address = is_scalar($webhook['address'] ?? null) ? (string) $webhook['address'] : '';
                $apiVersion = is_scalar($webhook['api_version'] ?? null) ? (string) $webhook['api_version'] : '';
                $webhooks[] = [
                    'id' => is_scalar($webhook['id'] ?? null) ? (string) $webhook['id'] : '',
                    'topic' => is_scalar($webhook['topic'] ?? null) ? (string) $webhook['topic'] : '',
                    'address' => $address,
                    'format' => strtoupper(is_scalar($webhook['format'] ?? null) ? (string) $webhook['format'] : 'json'),
                    'created_at' => is_scalar($webhook['created_at'] ?? null) ? (string) $webhook['created_at'] : '',
                    'api_version' => $apiVersion,
                    'healthy' => str_starts_with($address, 'https://') && ($apiVersion === '' || $apiVersion === ShopifyAdminClient::API_VERSION),
                ];
            }

            return ['ok' => true, 'error' => '', 'webhooks' => $webhooks];
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Shopify webhooks could not be loaded.', 'webhooks' => []];
        }
    }
}
