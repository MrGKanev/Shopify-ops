<?php

namespace App\Application\Orders;

use App\Domain\Orders\RemediationUnavailable;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class LoadOrderForRemediation
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly ShipStationClientFactory $clients) {}

    /** @return array<string, mixed> */
    public function shopify(Store $store, string $number): array
    {
        $number = ltrim(trim($number), '#');
        $orders = array_values(array_filter($this->shopify->findByOrderNumber($store, $number),
            fn (array $order): bool => ltrim((string) ($order['name'] ?? ''), '#') === $number
                || (string) ($order['order_number'] ?? '') === $number));
        if (count($orders) !== 1 || empty($orders[0]['id'])) {
            throw new RemediationUnavailable('Exactly one matching Shopify order is required.');
        }

        return $orders[0];
    }

    public function client(Store $store): ShipStationClientContract
    {
        return $this->clients->forStore($store) ?? throw new RemediationUnavailable('ShipStation credentials are required.');
    }

    /** @param array<string, mixed> $order
     * @return array<string, mixed>|null */
    public function shipStation(Store $store, array $order): ?array
    {
        $client = $this->client($store);
        $push = $store->pushLogs()->where('shopify_id', (string) $order['id'])->where('status', 'success')->latest('pushed_at')->latest('id')->first();
        if ($push !== null && ctype_digit((string) $push->shipstation_order_id)) {
            $target = $client->getOrder((int) $push->shipstation_order_id);
            $key = (string) ($target['orderKey'] ?? '');
            if ((string) ($target['orderId'] ?? '') !== (string) $push->shipstation_order_id
                || ! in_array($key, [(string) $order['id'], (string) ($order['admin_graphql_api_id'] ?? 'gid://shopify/Order/'.$order['id'])], true)) {
                throw new RemediationUnavailable('ShipStation order identity no longer matches the recorded push.');
            }

            return $target;
        }

        return $this->shipStationByNumber($store, (string) ($order['name'] ?? $order['order_number'] ?? ''));
    }

    /** @return array<string, mixed>|null */
    public function shipStationByNumber(Store $store, string $number): ?array
    {
        $client = $this->client($store);
        $number = ltrim($number, '#');
        $storeNumber = (string) $store->store_number;
        if (! ctype_digit($storeNumber) || (int) $storeNumber < 1) {
            throw new RemediationUnavailable('Configure the ShipStation store number before changing imported orders.');
        }
        $matches = array_values(array_filter($client->findByOrderNumber($number, shipStationStoreId: (int) $storeNumber),
            fn (array $candidate): bool => ltrim((string) ($candidate['orderNumber'] ?? ''), '#') === $number));
        if ($matches === []) {
            return null;
        }
        $matches = array_values(array_filter($matches, fn (array $candidate): bool => (string) ($candidate['advancedOptions']['storeId'] ?? '') === $storeNumber));
        if (count($matches) !== 1 || empty($matches[0]['orderId'])) {
            throw new RemediationUnavailable('Exactly one ShipStation order in the configured store is required.');
        }

        $target = $client->getOrder((int) $matches[0]['orderId']);
        if ((string) ($target['orderId'] ?? '') !== (string) $matches[0]['orderId']
            || (string) ($target['advancedOptions']['storeId'] ?? '') !== $storeNumber
            || ltrim((string) ($target['orderNumber'] ?? ''), '#') !== $number) {
            throw new RemediationUnavailable('ShipStation order identity changed during lookup.');
        }

        return $target;
    }
}
