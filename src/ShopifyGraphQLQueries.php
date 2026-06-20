<?php
declare(strict_types=1);

/**
 * Query-string builders and reusable Admin GraphQL field fragments for Shopify.
 */
class ShopifyGraphQLQueries
{
    public static function orderDateRangeQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:any',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    public static function paidOrdersQuery(string $startDate, string $endDate, bool $unfulfilledOnly = false): string
    {
        $filters = [
            'status:any',
            '(financial_status:paid OR financial_status:partially_paid)',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ];
        if ($unfulfilledOnly) {
            $filters[] = '(fulfillment_status:unfulfilled OR fulfillment_status:partial)';
        }

        return implode(' ', $filters);
    }

    public static function refundedOrdersQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:any',
            '(financial_status:refunded OR financial_status:partially_refunded)',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    public static function partiallyFulfilledOrdersQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:open',
            'fulfillment_status:partial',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    public static function fulfilledOrPartialOrdersQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:any',
            '(fulfillment_status:fulfilled OR fulfillment_status:partial)',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    public static function orderEventDateRangeQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'subject_type:ORDER',
            'comments:false',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    public static function productStatusGraphQLArg(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '' || $normalized === 'any') {
            return '';
        }

        if (!in_array($normalized, ['active', 'draft', 'archived'], true)) {
            throw new InvalidArgumentException("Unsupported Shopify product status: {$status}");
        }

        return ', query: "status:' . $normalized . '"';
    }

    public static function orderCoreFields(): string
    {
        return <<<'GQL'
                id
                legacyResourceId
                name
                createdAt
                cancelledAt
                email
                displayFinancialStatus
                displayFulfillmentStatus
                totalPriceSet { shopMoney { amount currencyCode } }

GQL;
    }

    public static function shippingAddressFields(): string
    {
        return <<<'GQL'
                shippingAddress {
                  firstName
                  lastName
                  name
                  company
                  address1
                  address2
                  city
                  province
                  provinceCode
                  country
                  countryCodeV2
                  zip
                  phone
                }

GQL;
    }

    public static function billingAddressFields(): string
    {
        return <<<'GQL'
                billingAddress {
                  firstName
                  lastName
                  name
                  company
                  address1
                  address2
                  city
                  province
                  provinceCode
                  country
                  countryCodeV2
                  zip
                  phone
                }

GQL;
    }

    public static function shippingLineFields(): string
    {
        return <<<'GQL'
                shippingLines(first: 250) {
                  nodes {
                    id
                    title
                    code
                    originalPriceSet { shopMoney { amount currencyCode } }
                  }
                }

GQL;
    }

    public static function lineItemFields(): string
    {
        return <<<'GQL'
                lineItems(first: 250) {
                  nodes {
                    id
                    title
                    name
                    sku
                    quantity
                    unfulfilledQuantity
                    variantTitle
                    originalUnitPriceSet { shopMoney { amount currencyCode } }
                  }
                }

GQL;
    }

    public static function fulfillmentFields(): string
    {
        return <<<'GQL'
                fulfillments(first: 250) {
                  id
                  legacyResourceId
                  createdAt
                  status
                  displayStatus
                  trackingInfo(first: 10) {
                    company
                    number
                    url
                  }
                  fulfillmentLineItems(first: 250) {
                    edges {
                      node {
                        quantity
                        lineItem {
                          id
                          title
                          name
                          sku
                          quantity
                          variantTitle
                          originalUnitPriceSet { shopMoney { amount currencyCode } }
                        }
                      }
                    }
                  }
                }

GQL;
    }

    public static function refundFields(): string
    {
        return <<<'GQL'
                refunds {
                  id
                  legacyResourceId
                  createdAt
                  note
                  totalRefundedSet { shopMoney { amount currencyCode } }
                  refundLineItems(first: 250) {
                    nodes {
                      quantity
                      subtotalSet { shopMoney { amount currencyCode } }
                      lineItem {
                        id
                        title
                        name
                        sku
                        quantity
                      }
                    }
                  }
                  transactions(first: 250) {
                    nodes {
                      id
                      kind
                      status
                      amountSet { shopMoney { amount currencyCode } }
                    }
                  }
                }

GQL;
    }

    public static function discountApplicationFields(): string
    {
        return <<<'GQL'
                discountApplications(first: 250) {
                  nodes {
                    __typename
                    allocationMethod
                    targetSelection
                    targetType
                    value {
                      __typename
                      ... on MoneyV2 {
                        amount
                        currencyCode
                      }
                      ... on PricingPercentageValue {
                        percentage
                      }
                    }
                    ... on DiscountCodeApplication {
                      code
                    }
                  }
                }

GQL;
    }

    public static function orderNoteFields(): string
    {
        return <<<'GQL'
                note

GQL;
    }

    public static function orderTagFields(): string
    {
        return <<<'GQL'
                tags

GQL;
    }

    public static function orderCancelReasonFields(): string
    {
        return <<<'GQL'
                cancelReason

GQL;
    }

    public static function eventFields(): string
    {
        return <<<'GQL'
                  __typename
                  id
                  action
                  appTitle
                  createdAt
                  message
                  ... on BasicEvent {
                    subjectId
                    subjectType
                  }

GQL;
    }
}
