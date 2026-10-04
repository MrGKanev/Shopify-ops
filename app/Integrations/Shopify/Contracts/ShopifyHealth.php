<?php

namespace App\Integrations\Shopify\Contracts;

use App\Models\Store;

interface ShopifyHealth
{
    /** @return array{shop_name: string, timezone: string, scopes: list<string>, requested_version: string, returned_version: string} */
    public function healthCheck(Store $store): array;
}
