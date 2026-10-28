<?php

namespace Tests\Support;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\Store;

/** Builds the real Shopify client and an unsaved store with fake credentials for client-level tests. */
trait BuildsShopifyAdminClient
{
    private function client(): ShopifyAdminClient
    {
        return app(ShopifyAdminClient::class);
    }

    private function store(): Store
    {
        return Store::factory()->make([
            'shopify_store' => 'acme',
            'shopify_access_token' => 'shpat_test-token',
        ]);
    }
}
