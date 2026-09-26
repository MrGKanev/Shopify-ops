<?php

namespace App\Application\Orders;

use App\Models\PushLog;
use App\Models\Store;
use Throwable;

class RecordPush
{
    public function handle(Store $store, string $orderNumber, string $shopifyId, int|string|null $shipstationOrderId): PushLog
    {
        return $store->pushLogs()->create(['order_number' => $orderNumber, 'shopify_id' => $shopifyId, 'shipstation_order_id' => $shipstationOrderId, 'pushed_at' => now(), 'status' => 'success']);
    }

    public function failed(Store $store, string $orderNumber, string $shopifyId, Throwable $exception): PushLog
    {
        return $store->pushLogs()->create([
            'order_number' => $orderNumber,
            'shopify_id' => $shopifyId,
            'shipstation_order_id' => null,
            'pushed_at' => now(),
            'status' => 'failed',
            'error_category' => $exception::class,
        ]);
    }
}
