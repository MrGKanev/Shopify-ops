<?php

namespace App\Application\Orders;

use App\Domain\Reports\AddressCheckAnalyzer;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use LogicException;
use RuntimeException;
use Throwable;

class PushOrderToShipStation
{
    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationClients,
        private readonly RecordPush $recordPush,
        private readonly AddressCheckAnalyzer $addresses = new AddressCheckAnalyzer,
    ) {}

    /**
     * The ShipStation createorder payload and the shipping address problems, without sending anything.
     *
     * @return array{payload: array<string, mixed>, address_issues: list<array{level: 'critical'|'warning', code: string, message: string}>}
     */
    public function preview(Store $store, string $orderNumber): array
    {
        $order = $this->findOrder($store, $orderNumber);

        return [
            'payload' => $this->client($store)->buildOrderPayload($order),
            'address_issues' => $this->addressIssues($order),
        ];
    }

    /**
     * Create the order in ShipStation. Critical shipping address problems stop the push unless the
     * operator confirmed pushing anyway.
     *
     * @return array{order_number: string, shopify_order_number: string, ss_order_id: mixed}
     *
     * @throws ShippingAddressNeedsReview
     */
    public function handle(Store $store, string $orderNumber, bool $confirmAddressIssues = false): array
    {
        $order = [];
        try {
            $order = $this->findOrder($store, $orderNumber);
        } catch (Throwable $exception) {
            $this->recordPush->failed($store, $orderNumber, '', $exception);

            throw $exception;
        }

        $criticalIssues = array_values(array_filter($this->addressIssues($order), fn (array $issue): bool => $issue['level'] === 'critical'));
        if ($criticalIssues !== [] && ! $confirmAddressIssues) {
            throw new ShippingAddressNeedsReview($orderNumber, $criticalIssues);
        }

        try {
            $created = $this->client($store)->createOrder($order);
        } catch (Throwable $exception) {
            $this->recordPush->failed($store, $orderNumber, (string) ($order['id'] ?? ''), $exception);

            throw $exception;
        }

        $ssOrderId = $created['orderId'] ?? null;
        $createdOrderNumber = (string) ($created['orderNumber'] ?? $orderNumber);

        $this->recordPush->handle($store, $createdOrderNumber, (string) ($order['id'] ?? ''), is_int($ssOrderId) || is_string($ssOrderId) ? $ssOrderId : null);

        return [
            'order_number' => $createdOrderNumber,
            'shopify_order_number' => $orderNumber,
            'ss_order_id' => $ssOrderId,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<array{level: 'critical'|'warning', code: string, message: string}>
     */
    private function addressIssues(array $order): array
    {
        $address = $order['shipping_address'] ?? $order['billing_address'] ?? null;

        return $this->addresses->check(is_array($address) ? $address : null, $order);
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
