<?php

namespace App\Application\Orders;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class SaveOrderNote
{
    public function __construct(private readonly ShopifyAdminGateway $shopify) {}

    public function handle(Store $store, string $orderId, string $note): void
    {
        $this->shopify->updateOrderNote($store, $orderId, $note);
    }
}
