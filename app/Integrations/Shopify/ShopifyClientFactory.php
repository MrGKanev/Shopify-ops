<?php

namespace App\Integrations\Shopify;

use App\Models\Store;

class ShopifyClientFactory
{
    public function __construct(
        private readonly ShopifyOrderNormalizer $orders,
        private readonly ShopifyOrderEventNormalizer $events,
        private readonly ShopifyPaymentNormalizer $payments,
    ) {}

    public function forStore(Store $store): ShopifyClient
    {
        return new ShopifyClient($store, $this->orders, $this->events, new ShopifyGraphqlTransport, $this->payments);
    }
}
