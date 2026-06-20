<?php
declare(strict_types=1);

/**
 * Direct order lookup operations.
 */
class ShopifyOrderLookup
{
    public function __construct(
        private readonly ShopifyGraphQLClient $client,
        private readonly ?Cache $cache = null
    ) {
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

    public function isOnHold(string $orderId): bool
    {
        $check = function () use ($orderId): bool {
            $query = <<<'GQL'
            query OrderFulfillmentOrdersHold($id: ID!, $after: String) {
              order(id: $id) {
                fulfillmentOrders(first: 250, after: $after) {
                  pageInfo { hasNextPage endCursor }
                  nodes {
                    id
                    status
                  }
                }
              }
            }
            GQL;

            $cursor = null;
            do {
                $data = $this->client->graphql($query, [
                    'id'    => ShopifyGraphQLNormalizer::orderGid($orderId),
                    'after' => $cursor,
                ]);

                $connection = $data['data']['order']['fulfillmentOrders'] ?? null;
                if (!is_array($connection)) {
                    return false;
                }

                foreach (($connection['nodes'] ?? []) as $fo) {
                    if (strtoupper((string)($fo['status'] ?? '')) === 'ON_HOLD') {
                        return true;
                    }
                }

                $hasNext = (bool)($connection['pageInfo']['hasNextPage'] ?? false);
                $cursor  = $connection['pageInfo']['endCursor'] ?? null;
            } while ($hasNext && $cursor);

            return false;
        };

        if ($this->cache) {
            return $this->cache->remember('shopify_hold', $orderId, $check);
        }

        return $check();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderEvents(string $orderId): array
    {
        $query = <<<'GQL'
        query GetOrderEvents($id: ID!, $after: String) {
          order(id: $id) {
            events(first: 250, sortKey: CREATED_AT, reverse: true, after: $after) {
              pageInfo { hasNextPage endCursor }
              edges {
                node {
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
                }
              }
            }
          }
        }
        GQL;

        $events = [];
        $cursor = null;
        do {
            $data = $this->client->graphql($query, [
                'id'    => ShopifyGraphQLNormalizer::orderGid($orderId),
                'after' => $cursor,
            ]);

            $connection = $data['data']['order']['events'] ?? null;
            if (!is_array($connection)) {
                return $events;
            }

            foreach (($connection['edges'] ?? []) as $edge) {
                $node = $edge['node'] ?? null;
                if (is_array($node)) {
                    $events[] = ShopifyGraphQLNormalizer::normalizeEvent($node, $orderId);
                }
            }

            $hasNext = (bool)($connection['pageInfo']['hasNextPage'] ?? false);
            $cursor  = $connection['pageInfo']['endCursor'] ?? null;
        } while ($hasNext && $cursor);

        return $events;
    }
}
