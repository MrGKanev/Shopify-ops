<?php

namespace App\Application\Orders;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class SaveOrderNote
{
    public function __construct(private readonly ShopifyOrders $shopify) {}

    public function handle(Store $store, string $orderId, string $note): void
    {
        $this->shopify->updateOrderNote($store, $orderId, $note);
    }
}
