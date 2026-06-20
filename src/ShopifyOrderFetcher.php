<?php
declare(strict_types=1);

/**
 * Shared GraphQL batch fetches for orders and order events.
 */
class ShopifyOrderFetcher
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchEventsByQuery(string $queryStr, int $maxPages = 1000): array
    {
        $events = [];
        $query = <<<'GQL'
        query FetchOrderEventsByQuery($query: String!, $after: String) {
          events(first: 250, sortKey: CREATED_AT, reverse: true, query: $query, after: $after) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                {{EVENT_FIELDS}}
              }
            }
          }
        }
        GQL;
        $query = str_replace('{{EVENT_FIELDS}}', ShopifyGraphQLQueries::eventFields(), $query);

        $this->client->paginateGraphQLVariables(
            $query,
            'events',
            ['query' => $queryStr],
            function (array $edges) use (&$events) {
                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? null;
                    if (is_array($node)) {
                        $events[] = ShopifyGraphQLNormalizer::normalizeEvent($node);
                    }
                }
            },
            $maxPages
        );

        return $events;
    }

    /**
     * @param array<int|string, int|string> $orderIds
     * @return array<string, array<string, mixed>>
     */
    public function fetchOrdersByIds(array $orderIds, string $nodeFields): array
    {
        $ids = [];
        foreach ($orderIds as $orderId) {
            $id = (string)$orderId;
            if ($id === '') {
                continue;
            }
            $ids[] = ShopifyGraphQLNormalizer::orderGid($id);
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $query = <<<'GQL'
        query FetchOrdersByIds($ids: [ID!]!) {
          nodes(ids: $ids) {
            ... on Order {
              {{NODE_FIELDS}}
            }
          }
        }
        GQL;
        $query = str_replace('{{NODE_FIELDS}}', $nodeFields, $query);

        $orders = [];
        foreach (array_chunk($ids, 250) as $chunk) {
            $data = $this->client->graphql($query, ['ids' => $chunk]);
            foreach (($data['data']['nodes'] ?? []) as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $order = ShopifyGraphQLNormalizer::normalizeOrder($node);
                $id    = (string)($order['id'] ?? '');
                if ($id !== '') {
                    $orders[$id] = $order;
                }
            }
        }

        return $orders;
    }

    /**
     * @param callable(array<string, mixed>): bool|null $nodeFilter
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersByQuery(string $queryStr, string $nodeFields, ?callable $nodeFilter = null): array
    {
        $all = [];
        $query = <<<'GQL'
        query FetchOrdersByQuery($query: String!, $after: String) {
          orders(first: 250, sortKey: CREATED_AT, reverse: true, query: $query, after: $after) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                {{NODE_FIELDS}}
              }
            }
          }
        }
        GQL;
        $query = str_replace('{{NODE_FIELDS}}', $nodeFields, $query);

        $this->client->paginateGraphQLVariables(
            $query,
            'orders',
            ['query' => $queryStr],
            function (array $edges) use (&$all, $nodeFilter) {
                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    if ($nodeFilter !== null && !$nodeFilter($node)) {
                        continue;
                    }
                    $all[] = ShopifyGraphQLNormalizer::normalizeOrder($node);
                }
            },
            1000
        );

        return $all;
    }
}
