<?php

namespace App\Application\Orders;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use LogicException;
use RuntimeException;

class PushOrderToShipStation
{
    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationClients,
        private readonly RecordPush $recordPush,
    ) {}

    /** @return array<string, mixed> the ShipStation createorder payload, without sending it */
    public function preview(Store $store, string $orderNumber): array
    {
        $order = $this->findOrder($store, $orderNumber);

        return $this->client($store)->buildOrderPayload($order);
    }

    /** @return array{order_number: string, shopify_order_number: string, ss_order_id: mixed} */
    public function handle(Store $store, string $orderNumber): array
    {
        $order = $this->findOrder($store, $orderNumber);
        $created = $this->client($store)->createOrder($order);
        $ssOrderId = $created['orderId'] ?? null;
        $createdOrderNumber = (string) ($created['orderNumber'] ?? $orderNumber);

        $this->recordPush->handle($store, $createdOrderNumber, (string) ($order['id'] ?? ''), is_int($ssOrderId) || is_string($ssOrderId) ? $ssOrderId : null);

        return [
            'order_number' => $createdOrderNumber,
            'shopify_order_number' => $orderNumber,
            'ss_order_id' => $ssOrderId,
        ];
    }

    /** @return array<string, mixed> */
    private function findOrder(Store $store, string $orderNumber): array
    {
        $orders = $this->shopify->findByOrderNumber($store, $orderNumber);

        return $orders[0] ?? throw new RuntimeException("Order {$orderNumber} not found in Shopify.");
    }

    private function client(Store $store): ShipStationClientContract
    {
        return $this->shipStationClients->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
    }
}
