<?php
declare(strict_types=1);

/**
 * Order audit workflows backed by Shopify Admin GraphQL.
 */
class ShopifyOrderAudits
{
    public function __construct(private readonly ShopifyOrderFetcher $orders)
    {
    }

    /**
     * Fetches paid orders in a date range with full shipping address fields for address validation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddressScan(string $startDate, string $endDate, bool $unfulfilledOnly = false): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::paidOrdersQuery($startDate, $endDate, $unfulfilledOnly),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::shippingAddressFields()
                . ShopifyGraphQLQueries::shippingLineFields()
        );
    }

    /**
     * Returns Shopify orders with refunded or partially_refunded financial status
     * in the given date range, including refund line details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForHighValue(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::paidOrdersQuery($startDate, $endDate, true),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::shippingAddressFields()
                . ShopifyGraphQLQueries::shippingLineFields()
        );
    }

    /**
     * Fetches orders whose shipping address was changed after the order was placed.
     *
     * @return array<int, array<string, mixed>> each entry has 'order' + 'changed_at'
     */
    public function fetchOrdersWithAddressChanges(string $startDate, string $endDate): array
    {
        $changed = $this->changedAddressOrderIds($startDate, $endDate);
        if (empty($changed)) return [];

        $ordersById = $this->orders->fetchOrdersByIds(
            array_keys($changed),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::shippingAddressFields()
        );

        $orders = [];
        foreach ($ordersById as $id => $order) {
            if (!isset($changed[$id])) {
                continue;
            }

            $orders[] = [
                'order'      => $order,
                'changed_at' => $changed[$id],
            ];
        }

        usort($orders, fn($a, $b) => strcmp($b['changed_at'], $a['changed_at']));

        return $orders;
    }

    /**
     * Finds orders that had content edits after placement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchEditedOrders(string $startDate, string $endDate): array
    {
        $byOrder = [];
        foreach ($this->orders->fetchEventsByQuery(ShopifyGraphQLQueries::orderEventDateRangeQuery($startDate, $endDate)) as $ev) {
            if (!ShopifyGraphQLNormalizer::isOrderEditEvent($ev)) {
                continue;
            }

            $id = (string)($ev['subject_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $ts = $ev['created_at'] ?? '';
            if (!isset($byOrder[$id])) {
                $byOrder[$id] = ['latest_at' => $ts, 'summary' => []];
            } elseif ($ts > $byOrder[$id]['latest_at']) {
                $byOrder[$id]['latest_at'] = $ts;
            }

            $short = ucfirst((string)($ev['message'] ?? ''));
            if ($short !== '' && count($byOrder[$id]['summary']) < 4 && !in_array($short, $byOrder[$id]['summary'], true)) {
                $byOrder[$id]['summary'][] = $short;
            }
        }

        if (empty($byOrder)) return [];

        $rows = [];
        $ordersById = $this->orders->fetchOrdersByIds(
            array_keys($byOrder),
            ShopifyGraphQLQueries::orderCoreFields()
        );

        foreach ($ordersById as $oid => $o) {
            $ev        = $byOrder[$oid] ?? [];
            $createdTs = strtotime($o['created_at'] ?? '');
            $editedTs  = strtotime($ev['latest_at']  ?? '');
            $diffMins  = ($createdTs && $editedTs) ? max(0, (int)(($editedTs - $createdTs) / 60)) : 0;
            $rows[] = [
                'shopify_id'   => (string)$oid,
                'order_number' => $o['name']                ?? '',
                'created_at'   => substr($o['created_at']   ?? '', 0, 10),
                'edited_at'    => substr($ev['latest_at']   ?? '', 0, 16),
                'diff_mins'    => $diffMins,
                'email'        => $o['email']               ?? '',
                'total'        => $o['total_price']         ?? '',
                'financial'    => $o['financial_status']    ?? '',
                'fulfillment'  => $o['fulfillment_status']  ?? '',
                'edit_summary' => $ev['summary']            ?? [],
            ];
        }

        usort($rows, fn($a, $b) => strcmp($b['edited_at'], $a['edited_at']));
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchRefundedOrders(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::refundedOrdersQuery($startDate, $endDate),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::refundFields(),
            fn(array $node) => in_array(
                ShopifyGraphQLNormalizer::normalizeFinancialStatus($node['displayFinancialStatus'] ?? null),
                ['refunded', 'partially_refunded'],
                true
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForCountryMismatch(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::paidOrdersQuery($startDate, $endDate),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::billingAddressFields()
                . ShopifyGraphQLQueries::shippingAddressFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchPartiallyFulfilledOrders(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::partiallyFulfilledOrdersQuery($startDate, $endDate),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::lineItemFields()
                . ShopifyGraphQLQueries::fulfillmentFields(),
            fn(array $node) => ShopifyGraphQLNormalizer::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null) === 'partial'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchFulfilledOrdersWithTracking(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::fulfilledOrPartialOrdersQuery($startDate, $endDate),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::fulfillmentFields(),
            fn(array $node) => in_array(
                ShopifyGraphQLNormalizer::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null),
                ['fulfilled', 'partial'],
                true
            )
        );
    }

    /**
     * @return array<int, array{order: array, changed_at: string, fulfillment_at: string}>
     */
    public function fetchPostShipAddressChanges(string $startDate, string $endDate): array
    {
        $changed = $this->changedAddressOrderIds($startDate, $endDate);
        if (empty($changed)) return [];

        $orders = [];
        $ordersById = $this->orders->fetchOrdersByIds(
            array_keys($changed),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::shippingAddressFields()
                . ShopifyGraphQLQueries::fulfillmentFields()
        );

        foreach ($ordersById as $oid => $o) {
            $changedAt = $changed[$oid] ?? '';

            $firstFulfillAt = '';
            foreach ($o['fulfillments'] ?? [] as $f) {
                $fa = $f['created_at'] ?? '';
                if ($fa && (!$firstFulfillAt || $fa < $firstFulfillAt)) {
                    $firstFulfillAt = $fa;
                }
            }

            if (!$firstFulfillAt || $changedAt <= $firstFulfillAt) continue;

            $orders[] = [
                'order'          => $o,
                'changed_at'     => $changedAt,
                'fulfillment_at' => $firstFulfillAt,
            ];
        }

        usort($orders, fn($a, $b) => strcmp($b['changed_at'], $a['changed_at']));
        return $orders;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersWithNotes(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::paidOrdersQuery($startDate, $endDate, true),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::orderNoteFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddrDupes(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::paidOrdersQuery($startDate, $endDate),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::shippingAddressFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForSla(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::paidOrdersQuery($startDate, $endDate),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::shippingAddressFields()
                . ShopifyGraphQLQueries::shippingLineFields()
                . ShopifyGraphQLQueries::lineItemFields()
                . ShopifyGraphQLQueries::fulfillmentFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchCancelledOrders(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::orderDateRangeQuery($startDate, $endDate),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::orderCancelReasonFields(),
            fn(array $node) => !empty($node['cancelledAt'])
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForDiscountAudit(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::paidOrdersQuery($startDate, $endDate),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::shippingAddressFields()
                . ShopifyGraphQLQueries::discountApplicationFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForTagPolicy(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            ShopifyGraphQLQueries::paidOrdersQuery($startDate, $endDate),
            ShopifyGraphQLQueries::orderCoreFields()
                . ShopifyGraphQLQueries::orderTagFields()
        );
    }

    /**
     * @return array<string, string>
     */
    private function changedAddressOrderIds(string $startDate, string $endDate): array
    {
        $changed = [];
        foreach ($this->orders->fetchEventsByQuery(ShopifyGraphQLQueries::orderEventDateRangeQuery($startDate, $endDate)) as $ev) {
            if (!ShopifyGraphQLNormalizer::isAddressChangeEvent($ev)) {
                continue;
            }

            $id = (string)($ev['subject_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $ts = $ev['created_at'] ?? '';
            if (!isset($changed[$id]) || $ts > $changed[$id]) {
                $changed[$id] = $ts;
            }
        }

        return $changed;
    }
}
