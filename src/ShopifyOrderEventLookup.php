<?php
declare(strict_types=1);

/**
 * Event log lookup for Shopify orders.
 */
class ShopifyOrderEventLookup
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
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
