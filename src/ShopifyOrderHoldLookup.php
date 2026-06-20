<?php
declare(strict_types=1);

/**
 * Fulfillment hold-state lookup for Shopify orders.
 */
class ShopifyOrderHoldLookup
{
    public function __construct(
        private readonly ShopifyGraphQLClient $client,
        private readonly ?Cache $cache = null
    ) {
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
}
