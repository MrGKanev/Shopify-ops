<?php

namespace App\Integrations\Shopify\Contracts;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Models\Store;

/**
 * Order lookups, order notes, order events and order-based report candidates.
 */
interface ShopifyOrders
{
    /**
     * @return list<array<string, mixed>>
     */
    public function findByOrderNumber(Store $store, string $orderNumber): array;

    /**
     * @param  list<mixed>  $orderNumbers
     * @return array<int|string, list<array<string, mixed>>>
     */
    public function findByOrderNumbers(Store $store, array $orderNumbers): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrderEvents(Store $store, string $orderId): array;

    /** @throws ShopifyGraphqlException on an invalid order id or Shopify-reported user error */
    public function updateOrderNote(Store $store, string $orderId, string $note): void;

    /**
     * @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(Store $store, string $tag, ?string $startDate = null, ?string $endDate = null): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function highValueOrderCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function countryMismatchCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function fraudRiskCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function emailCheckCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function addressCheckCandidates(Store $store, string $startDate, string $endDate, bool $unfulfilledOnly): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function discountAbuseCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function sameIpCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function duplicateOrderCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return list<array<string, mixed>> */
    public function orderMetafieldDefinitions(Store $store): array;

    /** @return array{orders: list<array<string, mixed>>, scanned: int, with_metafield: int, sample_values: list<string>, pages: int, truncated: bool} */
    public function searchOrdersByMetafield(Store $store, string $namespace, string $key, string $value, ?string $startDate, ?string $endDate): array;

    /**
     * @param  list<int|string>  $orderIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function orderMetafields(Store $store, array $orderIds): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function tagPolicyCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function noteFlagCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function fulfilledItemCandidates(Store $store, string $startDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function shippingMarginCandidates(Store $store, string $startDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function fulfillmentSlaCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function partialFulfillmentCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{fulfillment_orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function onHoldFulfillmentCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function noTrackingCandidates(Store $store, string $startDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function itemMismatchCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{events: list<array<string, mixed>>, orders: array<string, array<string, mixed>>, pages: int, truncated: bool} */
    public function orderEditCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{events: list<array<string, mixed>>, orders: array<string, array<string, mixed>>, pages: int, truncated: bool} */
    public function addressChangeCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function tagAuditCandidates(Store $store, string $startDate, string $endDate): array;
}
