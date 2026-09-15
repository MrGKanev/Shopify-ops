<?php

namespace App\Application\Orders;

use App\Models\PushLog;
use App\Models\Store;

class RecordPush
{
    public function handle(Store $store, string $orderNumber, string $shopifyId, int|string|null $shipstationOrderId): PushLog
    {
        return $store->pushLogs()->create(['order_number' => $orderNumber, 'shopify_id' => $shopifyId, 'shipstation_order_id' => $shipstationOrderId, 'pushed_at' => now()]);
    }
}
