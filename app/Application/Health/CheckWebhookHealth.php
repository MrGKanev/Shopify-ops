<?php

namespace App\Application\Health;

use App\Models\Store;
use Throwable;

class CheckWebhookHealth
{
    public function __construct(private readonly ManageWebhookSubscriptions $subscriptions) {}

    /** @return array<string, mixed> */
    public function handle(Store $store): array
    {
        $result = ['ok' => false, 'error' => '', 'webhooks' => [], 'missing' => [], 'callback' => $this->subscriptions->callback($store)];
        if ($store->missingShopifyCredentials()) {
            return [...$result, 'error' => 'Shopify credentials are incomplete.'];
        }
        try {
            $webhooks = $this->subscriptions->subscriptions($store);

            return [...$result, 'ok' => true, 'webhooks' => $webhooks, 'missing' => $this->subscriptions->missing($webhooks)];
        } catch (Throwable) {
            return [...$result, 'error' => 'Shopify webhooks could not be loaded.'];
        }
    }
}
