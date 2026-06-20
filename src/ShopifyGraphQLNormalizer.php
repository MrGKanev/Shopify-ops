<?php
declare(strict_types=1);

/**
 * Maps Shopify Admin GraphQL payloads into the legacy REST-shaped arrays
 * consumed by the rest of the app.
 */
class ShopifyGraphQLNormalizer
{
    public static function orderGid(string $orderId): string
    {
        $trimmed = trim($orderId);
        if (str_starts_with($trimmed, 'gid://shopify/Order/')) {
            return $trimmed;
        }

        if (!ctype_digit($trimmed)) {
            throw new InvalidArgumentException("Unsupported Shopify order ID: {$orderId}");
        }

        return "gid://shopify/Order/{$trimmed}";
    }

    /**
     * Maps Admin GraphQL Order nodes into the legacy REST order shape used by the UI and ShipStation push.
     *
     * @return array<string, mixed>
     */
    public static function normalizeOrder(array $node): array
    {
        $id   = self::legacyId($node['legacyResourceId'] ?? null, $node['id'] ?? null);
        $name = (string)($node['name'] ?? '');

        $order = [
            'id'                   => $id,
            'order_number'         => self::orderNumberFromName($name),
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
            $order['shipping_address'] = self::normalizeAddress($node['shippingAddress'] ?? null);
        }
        if (array_key_exists('billingAddress', $node)) {
            $order['billing_address'] = self::normalizeAddress($node['billingAddress'] ?? null);
        }
        if (isset($node['lineItems']['nodes'])) {
            $order['line_items'] = array_map(
                fn($lineItem) => self::normalizeLineItem($lineItem),
                $node['lineItems']['nodes']
            );
        }
        if (isset($node['shippingLines']['nodes'])) {
            $order['shipping_lines'] = array_map(
                fn($shippingLine) => self::normalizeShippingLine($shippingLine),
                $node['shippingLines']['nodes']
            );
        }
        if (isset($node['fulfillments'])) {
            $order['fulfillments'] = array_map(
                fn($fulfillment) => self::normalizeFulfillment($fulfillment),
                (array)$node['fulfillments']
            );
        }
        if (isset($node['refunds'])) {
            $order['refunds'] = array_map(
                fn($refund) => self::normalizeRefund($refund),
                (array)$node['refunds']
            );
        }
        if (isset($node['discountApplications']['nodes'])) {
            $order['discount_codes'] = array_values(array_filter(array_map(
                fn($discount) => self::normalizeDiscountCode($discount),
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

    /**
     * @return array<string, mixed>
     */
    public static function normalizeEvent(array $event, ?string $fallbackOrderId = null): array
    {
        $subjectGid = (string)($event['subjectId'] ?? '');
        if ($subjectGid === '' && $fallbackOrderId !== null) {
            $subjectGid = self::orderGid($fallbackOrderId);
        }

        $subjectId = $subjectGid !== '' ? self::legacyId(null, $subjectGid) : '';
        $action    = strtolower((string)($event['action'] ?? ''));

        return [
            'id'                   => self::legacyId(null, $event['id'] ?? null),
            'admin_graphql_api_id' => $event['id'] ?? '',
            'verb'                 => $action,
            'action'               => $action,
            'created_at'           => $event['createdAt'] ?? '',
            'message'              => (string)($event['message'] ?? ''),
            'subject_id'           => $subjectId,
            'subject_type'         => strtolower((string)($event['subjectType'] ?? 'Order')),
            'subject_graphql_api_id' => $subjectGid,
            'app_title'            => $event['appTitle'] ?? '',
        ];
    }

    public static function isAddressChangeEvent(array $event): bool
    {
        $haystack = strtolower(trim(
            (string)($event['verb'] ?? '') . ' ' .
            (string)($event['action'] ?? '') . ' ' .
            (string)($event['message'] ?? '')
        ));

        return str_contains($haystack, 'shipping address')
            || str_contains($haystack, 'address was')
            || str_contains($haystack, 'shipping_address');
    }

    public static function isOrderEditEvent(array $event): bool
    {
        $verb = strtolower((string)($event['verb'] ?? $event['action'] ?? ''));
        $msg  = strtolower((string)($event['message'] ?? ''));

        return $verb === 'edit_complete'
            || str_contains($msg, 'was edited')
            || str_contains($msg, 'were edited')
            || str_contains($msg, 'item was added')
            || str_contains($msg, 'item was removed')
            || str_contains($msg, 'discount was added')
            || str_contains($msg, 'discount was removed')
            || str_contains($msg, 'note was updated')
            || str_contains($msg, 'custom attributes');
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeMetafield(array $metafield, string $ownerId): array
    {
        return [
            'id'                   => self::legacyId(null, $metafield['id'] ?? null),
            'namespace'            => $metafield['namespace'] ?? '',
            'key'                  => $metafield['key'] ?? '',
            'value'                => $metafield['value'] ?? '',
            'type'                 => $metafield['type'] ?? '',
            'owner_id'             => self::legacyId(null, self::orderGid($ownerId)),
            'owner_resource'       => 'order',
            'created_at'           => $metafield['createdAt'] ?? '',
            'updated_at'           => $metafield['updatedAt'] ?? '',
            'admin_graphql_api_id' => $metafield['id'] ?? '',
        ];
    }

    /**
     * Maps Admin GraphQL Product nodes into the legacy REST product shape used by the UI.
     *
     * @return array<string, mixed>
     */
    public static function normalizeProduct(array $node): array
    {
        $productId = self::legacyId($node['legacyResourceId'] ?? null, $node['id'] ?? null);
        $images    = array_fill(0, max(0, (int)($node['mediaCount']['count'] ?? 0)), []);

        $variants = [];
        foreach (($node['variants']['edges'] ?? []) as $edge) {
            $variant = $edge['node'] ?? [];
            $variants[] = [
                'id'                   => self::legacyId($variant['legacyResourceId'] ?? null, $variant['id'] ?? null),
                'product_id'           => $productId,
                'title'                => $variant['title'] ?? '',
                'sku'                  => $variant['sku'] ?? '',
                'barcode'              => $variant['barcode'] ?? null,
                'inventory_quantity'   => (int)($variant['inventoryQuantity'] ?? 0),
                'inventory_policy'     => strtolower((string)($variant['inventoryPolicy'] ?? '')),
                'inventory_management' => ($variant['inventoryItem']['tracked'] ?? false) ? 'shopify' : null,
                'admin_graphql_api_id' => $variant['id'] ?? '',
            ];
        }

        return [
            'id'                   => $productId,
            'title'                => $node['title'] ?? '',
            'status'               => strtolower((string)($node['status'] ?? '')),
            'body_html'            => $node['descriptionHtml'] ?? '',
            'vendor'               => $node['vendor'] ?? '',
            'product_type'         => $node['productType'] ?? '',
            'images'               => $images,
            'variants'             => $variants,
            'admin_graphql_api_id' => $node['id'] ?? '',
        ];
    }

    private static function orderNumberFromName(string $name): int|string
    {
        $number = ltrim(trim($name), '#');
        return ctype_digit($number) ? (int)$number : $number;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'first_name'    => $address['firstName'] ?? '',
            'last_name'     => $address['lastName'] ?? '',
            'name'          => $address['name'] ?? '',
            'company'       => $address['company'] ?? null,
            'address1'      => $address['address1'] ?? '',
            'address2'      => $address['address2'] ?? '',
            'city'          => $address['city'] ?? '',
            'province'      => $address['province'] ?? '',
            'province_code' => $address['provinceCode'] ?? '',
            'country'       => $address['country'] ?? '',
            'country_code'  => $address['countryCodeV2'] ?? '',
            'zip'           => $address['zip'] ?? '',
            'phone'         => $address['phone'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeLineItem(array $lineItem): array
    {
        $normalized = [
            'id'                   => self::legacyId(null, $lineItem['id'] ?? null),
            'title'                => $lineItem['title'] ?? $lineItem['name'] ?? '',
            'name'                 => $lineItem['name'] ?? $lineItem['title'] ?? '',
            'sku'                  => $lineItem['sku'] ?? '',
            'quantity'             => (int)($lineItem['quantity'] ?? 0),
            'variant_title'        => $lineItem['variantTitle'] ?? null,
            'price'                => $lineItem['originalUnitPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $lineItem['id'] ?? '',
        ];

        if (array_key_exists('unfulfilledQuantity', $lineItem)) {
            $normalized['fulfillable_quantity'] = (int)($lineItem['unfulfilledQuantity'] ?? 0);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeShippingLine(array $shippingLine): array
    {
        return [
            'id'                   => self::legacyId(null, $shippingLine['id'] ?? null),
            'title'                => $shippingLine['title'] ?? '',
            'code'                 => $shippingLine['code'] ?? '',
            'price'                => $shippingLine['originalPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $shippingLine['id'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeFulfillment(array $fulfillment): array
    {
        $trackingInfo = array_values(array_filter(
            (array)($fulfillment['trackingInfo'] ?? []),
            fn($tracking) => is_array($tracking)
        ));
        $firstTracking = $trackingInfo[0] ?? [];

        $lineItems = [];
        foreach (($fulfillment['fulfillmentLineItems']['edges'] ?? []) as $edge) {
            $node = $edge['node'] ?? [];
            if (!is_array($node)) {
                continue;
            }

            $lineItem = self::normalizeLineItem((array)($node['lineItem'] ?? []));
            $lineItem['quantity'] = (int)($node['quantity'] ?? $lineItem['quantity'] ?? 0);
            $lineItems[] = $lineItem;
        }

        return [
            'id'                   => self::legacyId($fulfillment['legacyResourceId'] ?? null, $fulfillment['id'] ?? null),
            'admin_graphql_api_id' => $fulfillment['id'] ?? '',
            'created_at'           => $fulfillment['createdAt'] ?? '',
            'status'               => strtolower((string)($fulfillment['status'] ?? '')),
            'display_status'       => strtolower((string)($fulfillment['displayStatus'] ?? '')),
            'shipment_status'      => strtolower((string)($fulfillment['displayStatus'] ?? '')),
            'tracking_company'     => $firstTracking['company'] ?? '',
            'tracking_number'      => $firstTracking['number'] ?? '',
            'tracking_url'         => $firstTracking['url'] ?? '',
            'tracking_numbers'     => array_values(array_filter(array_map(fn($tracking) => $tracking['number'] ?? '', $trackingInfo))),
            'tracking_urls'        => array_values(array_filter(array_map(fn($tracking) => $tracking['url'] ?? '', $trackingInfo))),
            'line_items'           => $lineItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeRefund(array $refund): array
    {
        $refundLineItems = [];
        foreach (($refund['refundLineItems']['nodes'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }

            $lineItem = (array)($node['lineItem'] ?? []);
            $refundLineItems[] = [
                'quantity'     => (int)($node['quantity'] ?? 0),
                'subtotal'     => $node['subtotalSet']['shopMoney']['amount'] ?? '0.00',
                'line_item_id' => self::legacyId(null, $lineItem['id'] ?? null),
                'line_item'    => self::normalizeLineItem($lineItem),
            ];
        }

        $transactions = [];
        foreach (($refund['transactions']['nodes'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }

            $transactions[] = [
                'id'                   => self::legacyId(null, $node['id'] ?? null),
                'kind'                 => strtolower((string)($node['kind'] ?? '')),
                'status'               => strtolower((string)($node['status'] ?? '')),
                'amount'               => $node['amountSet']['shopMoney']['amount'] ?? '0.00',
                'admin_graphql_api_id' => $node['id'] ?? '',
            ];
        }

        return [
            'id'                   => self::legacyId($refund['legacyResourceId'] ?? null, $refund['id'] ?? null),
            'admin_graphql_api_id' => $refund['id'] ?? '',
            'created_at'           => $refund['createdAt'] ?? '',
            'note'                 => $refund['note'] ?? '',
            'total_refunded'       => $refund['totalRefundedSet']['shopMoney']['amount'] ?? '0.00',
            'refund_line_items'    => $refundLineItems,
            'transactions'         => $transactions,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeDiscountCode(array $discount): ?array
    {
        if (($discount['__typename'] ?? '') !== 'DiscountCodeApplication') {
            return null;
        }

        $code = trim((string)($discount['code'] ?? ''));
        if ($code === '') {
            return null;
        }

        $value = (array)($discount['value'] ?? []);
        $type = match ($value['__typename'] ?? '') {
            'MoneyV2' => 'fixed_amount',
            'PricingPercentageValue' => 'percentage',
            default => strtolower((string)($value['__typename'] ?? '')),
        };

        return [
            'code'             => $code,
            'amount'           => $value['amount'] ?? (isset($value['percentage']) ? (string)$value['percentage'] : ''),
            'type'             => $type,
            'allocation_method' => strtolower((string)($discount['allocationMethod'] ?? '')),
            'target_selection' => strtolower((string)($discount['targetSelection'] ?? '')),
            'target_type'      => strtolower((string)($discount['targetType'] ?? '')),
        ];
    }

    private static function legacyId(mixed $legacyResourceId, mixed $gid): int|string
    {
        $id = (string)($legacyResourceId ?? '');
        if ($id === '' && is_string($gid) && preg_match('~/(\d+)(?:\?.*)?$~', $gid, $matches)) {
            $id = $matches[1];
        }

        return ctype_digit($id) ? (int)$id : $id;
    }
}
