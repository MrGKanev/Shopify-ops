<?php
declare(strict_types=1);

/**
 * Query-backed order audit fetchers.
 */
class ShopifyOrderQueryAudits
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
}
