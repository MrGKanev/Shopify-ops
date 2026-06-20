<?php
declare(strict_types=1);

require_once __DIR__ . '/ShopifyGraphQLQueryStrings.php';
require_once __DIR__ . '/ShopifyGraphQLFieldFragments.php';

/**
 * Backward-compatible facade for Shopify Admin GraphQL query helpers.
 */
class ShopifyGraphQLQueries
{
    public static function orderDateRangeQuery(string $startDate, string $endDate): string
    {
        return ShopifyGraphQLQueryStrings::orderDateRangeQuery($startDate, $endDate);
    }

    public static function paidOrdersQuery(string $startDate, string $endDate, bool $unfulfilledOnly = false): string
    {
        return ShopifyGraphQLQueryStrings::paidOrdersQuery($startDate, $endDate, $unfulfilledOnly);
    }

    public static function refundedOrdersQuery(string $startDate, string $endDate): string
    {
        return ShopifyGraphQLQueryStrings::refundedOrdersQuery($startDate, $endDate);
    }

    public static function partiallyFulfilledOrdersQuery(string $startDate, string $endDate): string
    {
        return ShopifyGraphQLQueryStrings::partiallyFulfilledOrdersQuery($startDate, $endDate);
    }

    public static function fulfilledOrPartialOrdersQuery(string $startDate, string $endDate): string
    {
        return ShopifyGraphQLQueryStrings::fulfilledOrPartialOrdersQuery($startDate, $endDate);
    }

    public static function orderEventDateRangeQuery(string $startDate, string $endDate): string
    {
        return ShopifyGraphQLQueryStrings::orderEventDateRangeQuery($startDate, $endDate);
    }

    public static function productStatusGraphQLArg(string $status): string
    {
        return ShopifyGraphQLQueryStrings::productStatusGraphQLArg($status);
    }

    public static function orderCoreFields(): string
    {
        return ShopifyGraphQLFieldFragments::orderCoreFields();
    }

    public static function shippingAddressFields(): string
    {
        return ShopifyGraphQLFieldFragments::shippingAddressFields();
    }

    public static function billingAddressFields(): string
    {
        return ShopifyGraphQLFieldFragments::billingAddressFields();
    }

    public static function shippingLineFields(): string
    {
        return ShopifyGraphQLFieldFragments::shippingLineFields();
    }

    public static function lineItemFields(): string
    {
        return ShopifyGraphQLFieldFragments::lineItemFields();
    }

    public static function fulfillmentFields(): string
    {
        return ShopifyGraphQLFieldFragments::fulfillmentFields();
    }

    public static function refundFields(): string
    {
        return ShopifyGraphQLFieldFragments::refundFields();
    }

    public static function discountApplicationFields(): string
    {
        return ShopifyGraphQLFieldFragments::discountApplicationFields();
    }

    public static function orderNoteFields(): string
    {
        return ShopifyGraphQLFieldFragments::orderNoteFields();
    }

    public static function orderTagFields(): string
    {
        return ShopifyGraphQLFieldFragments::orderTagFields();
    }

    public static function orderCancelReasonFields(): string
    {
        return ShopifyGraphQLFieldFragments::orderCancelReasonFields();
    }

    public static function eventFields(): string
    {
        return ShopifyGraphQLFieldFragments::eventFields();
    }
}
