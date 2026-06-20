<?php
declare(strict_types=1);

require_once __DIR__ . '/ShopifyGraphQLIds.php';
require_once __DIR__ . '/ShopifyOrderNormalizer.php';
require_once __DIR__ . '/ShopifyEventNormalizer.php';
require_once __DIR__ . '/ShopifyMetafieldNormalizer.php';
require_once __DIR__ . '/ShopifyProductNormalizer.php';

/**
 * Backward-compatible facade for Shopify Admin GraphQL normalizers.
 */
class ShopifyGraphQLNormalizer
{
    public static function orderGid(string $orderId): string
    {
        return ShopifyGraphQLIds::orderGid($orderId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeOrder(array $node): array
    {
        return ShopifyOrderNormalizer::normalizeOrder($node);
    }

    public static function normalizeFinancialStatus(mixed $status): string
    {
        return ShopifyOrderNormalizer::normalizeFinancialStatus($status);
    }

    public static function normalizeFulfillmentStatus(mixed $status): ?string
    {
        return ShopifyOrderNormalizer::normalizeFulfillmentStatus($status);
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeEvent(array $event, ?string $fallbackOrderId = null): array
    {
        return ShopifyEventNormalizer::normalizeEvent($event, $fallbackOrderId);
    }

    public static function isAddressChangeEvent(array $event): bool
    {
        return ShopifyEventNormalizer::isAddressChangeEvent($event);
    }

    public static function isOrderEditEvent(array $event): bool
    {
        return ShopifyEventNormalizer::isOrderEditEvent($event);
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeMetafield(array $metafield, string $ownerId): array
    {
        return ShopifyMetafieldNormalizer::normalizeMetafield($metafield, $ownerId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeProduct(array $node): array
    {
        return ShopifyProductNormalizer::normalizeProduct($node);
    }
}
