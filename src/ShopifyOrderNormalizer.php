<?php
declare(strict_types=1);

require_once __DIR__ . '/ShopifyGraphQLIds.php';
require_once __DIR__ . '/ShopifyOrderComponentNormalizer.php';

/**
 * Maps Shopify Admin GraphQL Order payloads into the legacy REST-shaped arrays
 * consumed by the rest of the app.
 */
class ShopifyOrderNormalizer
{
    /**
     * Maps Admin GraphQL Order nodes into the legacy REST order shape used by the UI and ShipStation push.
     *
     * @return array<string, mixed>
     */
    public static function normalizeOrder(array $node): array
    {
        $id   = ShopifyGraphQLIds::legacyId($node['legacyResourceId'] ?? null, $node['id'] ?? null);
        $name = (string)($node['name'] ?? '');

        $order = [
            'id'                   => $id,
            'order_number'         => ShopifyOrderComponentNormalizer::orderNumberFromName($name),
            'name'                 => $name,
            'created_at'           => $node['createdAt'] ?? '',
            'cancelled_at'         => $node['cancelledAt'] ?? null,
            'email'                => $node['email'] ?? '',
            'financial_status'     => self::normalizeFinancialStatus($node['displayFinancialStatus'] ?? null),
            'fulfillment_status'   => self::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null),
            'total_price'          => $node['totalPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $node['id'] ?? '',
        ];

        if (array_key_exists('totalTaxSet', $node)) {
            $order['total_tax'] = $node['totalTaxSet']['shopMoney']['amount'] ?? '0.00';
        }
        if (array_key_exists('cancelReason', $node)) {
            $reason = $node['cancelReason'] ?? null;
            $order['cancel_reason'] = $reason === null ? null : strtolower((string)$reason);
        }
        if (array_key_exists('note', $node)) {
            $order['note'] = $node['note'] ?? '';
        }
        if (array_key_exists('tags', $node)) {
            $order['tags'] = implode(', ', (array)($node['tags'] ?? []));
        }
        if (array_key_exists('shippingAddress', $node)) {
            $order['shipping_address'] = ShopifyOrderComponentNormalizer::normalizeAddress($node['shippingAddress'] ?? null);
        }
        if (array_key_exists('billingAddress', $node)) {
            $order['billing_address'] = ShopifyOrderComponentNormalizer::normalizeAddress($node['billingAddress'] ?? null);
        }
        if (isset($node['lineItems']['nodes'])) {
            $order['line_items'] = array_map(
                fn($lineItem) => ShopifyOrderComponentNormalizer::normalizeLineItem($lineItem),
                $node['lineItems']['nodes']
            );
        }
        if (isset($node['shippingLines']['nodes'])) {
            $order['shipping_lines'] = array_map(
                fn($shippingLine) => ShopifyOrderComponentNormalizer::normalizeShippingLine($shippingLine),
                $node['shippingLines']['nodes']
            );
        }
        if (isset($node['fulfillments'])) {
            $order['fulfillments'] = array_map(
                fn($fulfillment) => ShopifyOrderComponentNormalizer::normalizeFulfillment($fulfillment),
                (array)$node['fulfillments']
            );
        }
        if (isset($node['refunds'])) {
            $order['refunds'] = array_map(
                fn($refund) => ShopifyOrderComponentNormalizer::normalizeRefund($refund),
                (array)$node['refunds']
            );
        }
        if (isset($node['discountApplications']['nodes'])) {
            $order['discount_codes'] = array_values(array_filter(array_map(
                fn($discount) => ShopifyOrderComponentNormalizer::normalizeDiscountCode($discount),
                $node['discountApplications']['nodes']
            )));
        }

        return $order;
    }

    public static function normalizeFinancialStatus(mixed $status): string
    {
        return strtolower((string)($status ?? ''));
    }

    public static function normalizeFulfillmentStatus(mixed $status): ?string
    {
        $normalized = strtolower((string)($status ?? ''));
        return match ($normalized) {
            '', 'unfulfilled' => null,
            'partially_fulfilled' => 'partial',
            default => $normalized,
        };
    }
}
