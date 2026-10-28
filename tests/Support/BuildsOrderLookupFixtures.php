<?php

namespace Tests\Support;

use App\Models\Store;
use App\Models\User;

/** Store, user and upstream payloads shared by the order lookup tests. */
trait BuildsOrderLookupFixtures
{
    /**
     * @param  array<string, mixed>  $storeAttributes
     * @return array{User, Store}
     */
    private function makeUserAndStore(array $storeAttributes = []): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'shopify_store' => 'acme',
            'shopify_access_token' => 'shopify-active-token',
            'shipstation_api_key' => 'shipstation-active-key',
            'shipstation_api_secret' => 'shipstation-active-secret',
            'store_number' => '42',
            ...$storeAttributes,
        ]);
        $user->stores()->attach($store);

        return [$user, $store];
    }

    /**
     * @return array<string, mixed>
     */
    private function shopifyResponse(): array
    {
        return [
            'data' => [
                'orders' => [
                    'edges' => [[
                        'node' => [
                            'id' => 'gid://shopify/Order/123456789',
                            'legacyResourceId' => '123456789',
                            'name' => '#65075',
                            'createdAt' => '2026-08-30T10:15:00Z',
                            'cancelledAt' => null,
                            'email' => '<script>alert("shopify")</script>',
                            'displayFinancialStatus' => 'PAID',
                            'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
                            'totalPriceSet' => ['shopMoney' => ['amount' => '129.90', 'currencyCode' => 'EUR']],
                        ],
                    ]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function comparisonShopifyResponse(): array
    {
        $response = $this->shopifyResponse();
        $node = &$response['data']['orders']['edges'][0]['node'];
        $node['email'] = 'buyer@example.com';
        $node['displayFulfillmentStatus'] = 'FULFILLED';
        $node['shippingAddress'] = [
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'address1' => '1 Main Street',
            'city' => 'Sofia',
            'provinceCode' => 'SOF',
            'zip' => '1000',
            'countryCodeV2' => 'BG',
        ];
        $node['lineItems'] = [
            'nodes' => [
                ['id' => 'gid://shopify/LineItem/1', 'sku' => 'Widget', 'quantity' => 2, 'title' => 'Widget'],
                ['id' => 'gid://shopify/LineItem/2', 'sku' => 'Cable', 'quantity' => 1, 'title' => 'Cable'],
            ],
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ];

        return $response;
    }

    /** @return array<string, mixed> */
    private function matchingShipStationOrder(): array
    {
        return [
            'orderId' => 42,
            'advancedOptions' => ['storeId' => 42],
            'orderNumber' => '65075',
            'orderStatus' => 'shipped',
            'customerEmail' => 'buyer@example.com',
            'orderTotal' => 129.90,
            'shipTo' => [
                'name' => 'jane doe',
                'street1' => '1 main street',
                'city' => 'SOFIA',
                'state' => 'sof',
                'postalCode' => '1000',
                'country' => 'bg',
            ],
            'items' => [
                ['sku' => 'widget', 'quantity' => 2, 'name' => 'Widget'],
                ['sku' => 'cable', 'quantity' => 1, 'name' => 'Cable'],
            ],
        ];
    }
}
