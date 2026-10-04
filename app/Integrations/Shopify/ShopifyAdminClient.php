<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class ShopifyAdminClient implements ShopifyAdminGateway
{
    public const API_VERSION = ShopifyGraphqlTransport::API_VERSION;

    public function __construct(private readonly ShopifyClientFactory $factory) {}

    public function healthCheck(Store $store): array
    {
        return $this->factory->forStore($store)->healthCheck();
    }

    public function findByOrderNumber(Store $store, string $orderNumber): array
    {
        return $this->factory->forStore($store)->findByOrderNumber($orderNumber);
    }

    public function findByOrderNumbers(Store $store, array $orderNumbers): array
    {
        return $this->factory->forStore($store)->findByOrderNumbers($orderNumbers);
    }

    public function getOrderEvents(Store $store, string $orderId): array
    {
        return $this->factory->forStore($store)->getOrderEvents($orderId);
    }

    public function updateOrderNote(Store $store, string $orderId, string $note): void
    {
        $this->factory->forStore($store)->updateOrderNote($orderId, $note);
    }

    public function searchOrdersByTag(Store $store, string $tag, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->factory->forStore($store)->searchOrdersByTag($tag, $startDate, $endDate);
    }

    public function highValueOrderCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->highValueOrderCandidates($startDate, $endDate);
    }

    public function countryMismatchCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->countryMismatchCandidates($startDate, $endDate);
    }

    public function taxAuditCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->taxAuditCandidates($startDate, $endDate);
    }

    public function consentAuditCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->consentAuditCandidates($startDate, $endDate);
    }

    public function fraudRiskCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->fraudRiskCandidates($startDate, $endDate);
    }

    public function emailCheckCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->emailCheckCandidates($startDate, $endDate);
    }

    public function addressCheckCandidates(Store $store, string $startDate, string $endDate, bool $unfulfilledOnly): array
    {
        return $this->factory->forStore($store)->addressCheckCandidates($startDate, $endDate, $unfulfilledOnly);
    }

    public function discountAbuseCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->discountAbuseCandidates($startDate, $endDate);
    }

    public function sameIpCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->sameIpCandidates($startDate, $endDate);
    }

    public function duplicateOrderCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->duplicateOrderCandidates($startDate, $endDate);
    }

    public function customerLtvCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->customerLtvCandidates($startDate, $endDate);
    }

    public function customerOrderHistory(Store $store, string $email): array
    {
        return $this->factory->forStore($store)->customerOrderHistory($email);
    }

    public function orderMetafieldDefinitions(Store $store): array
    {
        return $this->factory->forStore($store)->orderMetafieldDefinitions();
    }

    public function searchOrdersByMetafield(Store $store, string $namespace, string $key, string $value, ?string $startDate, ?string $endDate): array
    {
        return $this->factory->forStore($store)->searchOrdersByMetafield($namespace, $key, $value, $startDate, $endDate);
    }

    public function orderMetafields(Store $store, array $orderIds): array
    {
        return $this->factory->forStore($store)->orderMetafields($orderIds);
    }

    public function tagPolicyCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->tagPolicyCandidates($startDate, $endDate);
    }

    public function openDisputes(Store $store): array
    {
        return $this->factory->forStore($store)->openDisputes();
    }

    public function noteFlagCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->noteFlagCandidates($startDate, $endDate);
    }

    public function repeatRefundCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->repeatRefundCandidates($startDate, $endDate);
    }

    public function refundTrackerCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->refundTrackerCandidates($startDate, $endDate);
    }

    public function returnedItemCandidates(Store $store, string $startDate): array
    {
        return $this->factory->forStore($store)->returnedItemCandidates($startDate);
    }

    public function fulfilledItemCandidates(Store $store, string $startDate): array
    {
        return $this->factory->forStore($store)->fulfilledItemCandidates($startDate);
    }

    public function shippingMarginCandidates(Store $store, string $startDate): array
    {
        return $this->factory->forStore($store)->shippingMarginCandidates($startDate);
    }

    public function fulfillmentSlaCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->fulfillmentSlaCandidates($startDate, $endDate);
    }

    public function partialFulfillmentCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->partialFulfillmentCandidates($startDate, $endDate);
    }

    public function onHoldFulfillmentCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->onHoldFulfillmentCandidates($startDate, $endDate);
    }

    public function noTrackingCandidates(Store $store, string $startDate): array
    {
        return $this->factory->forStore($store)->noTrackingCandidates($startDate);
    }

    public function itemMismatchCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->itemMismatchCandidates($startDate, $endDate);
    }

    public function orderEditCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->orderEditCandidates($startDate, $endDate);
    }

    public function addressChangeCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->addressChangeCandidates($startDate, $endDate);
    }

    public function tagAuditCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->tagAuditCandidates($startDate, $endDate);
    }

    public function productCompletenessCandidates(Store $store): array
    {
        return $this->factory->forStore($store)->productCompletenessCandidates();
    }

    public function skuDuplicatesCandidates(Store $store): array
    {
        return $this->factory->forStore($store)->skuDuplicatesCandidates();
    }

    public function inventoryOversellCandidates(Store $store): array
    {
        return $this->factory->forStore($store)->inventoryOversellCandidates();
    }

    public function inventoryAgingCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->inventoryAgingCandidates($startDate, $endDate);
    }

    public function inventoryForecastCandidates(Store $store, string $startDate, string $endDate): array
    {
        return $this->factory->forStore($store)->inventoryForecastCandidates($startDate, $endDate);
    }

    public function zombieProductsCandidates(Store $store): array
    {
        return $this->factory->forStore($store)->zombieProductsCandidates();
    }

    public function catalogQualityCandidates(Store $store): array
    {
        return $this->factory->forStore($store)->catalogQualityCandidates();
    }

    public function giftCardCandidates(Store $store): array
    {
        return $this->factory->forStore($store)->giftCardCandidates();
    }

    public function get(Store $store, string $resource, array $query = []): array
    {
        return $this->factory->forStore($store)->get($resource, $query);
    }

    public function graphql(Store $store, string $query, array $variables = []): array
    {
        return $this->factory->forStore($store)->graphql($query, $variables);
    }

    public function paginateGraphql(Store $store, string $query, string $rootKey, array $variables = [], int $maxPages = 20): array
    {
        return $this->factory->forStore($store)->paginateGraphql($query, $rootKey, $variables, $maxPages);
    }
}
