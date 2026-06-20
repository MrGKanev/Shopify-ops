<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Cached full-order range fetch used by the main ShipStation comparison audit.
 */
class OrderArchive
{
    public function __construct(
        private readonly Client $client,
        private readonly ?\Cache $cache = null
    ) {
    }

    /**
     * Returns every order created between $start and $end (inclusive).
     * Includes total_price and shipping_lines for filtering logic.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllOrders(string $startDate, string $endDate): array
    {
        $fetch = function () use ($startDate, $endDate): array {
            $all      = [];
            $queryStr = 'status:any created_at:>=' . $startDate . 'T00:00:00Z created_at:<=' . $endDate . 'T23:59:59Z';
            $query    = <<<'GQL'
            query FetchOrdersForAudit($query: String!, $after: String) {
              orders(first: 250, sortKey: CREATED_AT, query: $query, after: $after) {
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
              }
            }
            GQL;

            $this->client->paginateGraphQLVariables(
                $query,
                'orders',
                ['query' => $queryStr],
                function (array $edges) use (&$all) {
                    foreach ($edges as $edge) {
                        $all[] = Normalizer::normalizeOrder($edge['node'] ?? []);
                    }
                },
                1000
            );

            return $all;
        };

        if ($this->cache) {
            return $this->cache->remember('shopify', "{$startDate}|{$endDate}", function () use ($fetch, $startDate, $endDate) {
                $orders = $fetch();
                $this->logInfo('Shopify orders stored ({start} -> {end}); {count} orders', [
                    'start' => $startDate,
                    'end'   => $endDate,
                    'count' => count($orders),
                ]);
                return $orders;
            });
        }

        return $fetch();
    }

    private function logInfo(string $message, array $context = []): void
    {
        if (class_exists(\Logger::class)) {
            \Logger::getInstance()->info($message, $context);
        }
    }
}
