<?php
declare(strict_types=1);

/**
 * Direct Shopify order lookup operations.
 */
class ShopifyOrderDirectLookup
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        $clean = ltrim(trim($orderNumber), '#');
        $query = <<<'GQL'
        query FindOrderByName($query: String!) {
          orders(first: 10, query: $query) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                name
                createdAt
                cancelledAt
                email
                displayFinancialStatus
                displayFulfillmentStatus
                totalPriceSet { shopMoney { amount currencyCode } }
              }
            }
          }
        }
        GQL;

        $data  = $this->client->graphql($query, ['query' => "name:{$clean}"]);
        $edges = $data['data']['orders']['edges'] ?? [];
        return array_map(fn($edge) => ShopifyGraphQLNormalizer::normalizeOrder($edge['node'] ?? []), $edges);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $query = <<<'GQL'
        query GetOrderForRestShape($id: ID!) {
          order(id: $id) {
            id
            legacyResourceId
            name
            createdAt
            cancelledAt
            email
            note
            tags
            displayFinancialStatus
            displayFulfillmentStatus
            totalPriceSet { shopMoney { amount currencyCode } }
            totalTaxSet { shopMoney { amount currencyCode } }
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
            lineItems(first: 250) {
              nodes {
                id
                title
                name
                sku
                quantity
                variantTitle
                originalUnitPriceSet { shopMoney { amount currencyCode } }
              }
            }
            shippingLines(first: 250) {
              nodes {
                id
                title
                code
                originalPriceSet { shopMoney { amount currencyCode } }
              }
            }
          }
        }
        GQL;

        $data = $this->client->graphql($query, ['id' => ShopifyGraphQLNormalizer::orderGid($orderId)]);
        $node = $data['data']['order'] ?? null;
        return is_array($node) ? ShopifyGraphQLNormalizer::normalizeOrder($node) : [];
    }
}
