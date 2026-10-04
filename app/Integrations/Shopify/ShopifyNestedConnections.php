<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Models\Store;

/**
 * Follows cursors on connections nested inside a single resource (an order's line items and events, a product's variants).
 */
class ShopifyNestedConnections
{
    public function __construct(private readonly ShopifyGraphqlTransport $transport) {}

    /**
     * Every raw event node of one order, newest first; empty when the order does not exist.
     *
     * @return list<array<string, mixed>>
     */
    public function orderEvents(Store $store, string $orderGid): array
    {
        $query = ShopifyQueries::get('GetOrderEvents');
        $nodes = [];
        $cursor = null;
        $pages = 0;

        do {
            $requestedCursor = $cursor;
            $data = $this->transport->graphql($store, $query, ['id' => $orderGid, 'after' => $cursor])['data'];

            if (! is_array($data) || ! array_key_exists('order', $data)) {
                throw new ShopifyGraphqlException([], 'Shopify order events returned an unexpected response shape.');
            }

            if ($data['order'] === null && $pages === 0) {
                return [];
            }

            $connection = is_array($data['order']) ? ($data['order']['events'] ?? null) : null;

            if (! is_array($connection)
                || ! is_array($connection['edges'] ?? null)
                || ! is_array($connection['pageInfo'] ?? null)
                || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify order events returned an unexpected response shape.');
            }

            foreach ($connection['edges'] as $edge) {
                if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                    throw new ShopifyGraphqlException([], 'Shopify order events returned an unexpected response shape.');
                }

                $nodes[] = $edge['node'];
            }

            $hasNextPage = $connection['pageInfo']['hasNextPage'];
            $cursor = is_string($connection['pageInfo']['endCursor'] ?? null) ? $connection['pageInfo']['endCursor'] : null;
            $pages++;

            if ($hasNextPage && ($cursor === null || $cursor === '' || $cursor === $requestedCursor)) {
                throw new ShopifyGraphqlException([], 'Shopify order events returned an unexpected response shape.');
            }
        } while ($hasNextPage);

        return $nodes;
    }

    /**
     * Appends the remaining pages of an order node's `lineItems` connection, when the node selected one.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function completeLineItems(Store $store, array $order): array
    {
        if (! array_key_exists('lineItems', $order)) {
            return $order;
        }

        $connection = $order['lineItems'];

        if (! $this->isValidLineItemConnection($connection)) {
            throw new ShopifyGraphqlException([], 'Shopify line items returned an unexpected response shape.');
        }

        $pages = 1;

        while ($connection['pageInfo']['hasNextPage']) {
            if ($pages >= 20) {
                throw new ShopifyGraphqlException([], 'Shopify line item pagination exceeded its page limit.');
            }

            $cursor = $connection['pageInfo']['endCursor'] ?? null;
            $orderId = $order['id'] ?? null;

            if (! is_string($cursor) || $cursor === '' || ! is_string($orderId) || $orderId === '') {
                throw new ShopifyGraphqlException([], 'Shopify line items returned an unexpected response shape.');
            }

            $result = $this->transport->graphql($store, ShopifyQueries::get('OrderLineItems'), ['id' => $orderId, 'after' => $cursor]);
            $connection = $result['data']['order']['lineItems'] ?? null;

            if (! $this->isValidLineItemConnection($connection)) {
                throw new ShopifyGraphqlException([], 'Shopify line items returned an unexpected response shape.');
            }

            foreach ($connection['nodes'] as $lineItem) {
                $order['lineItems']['nodes'][] = $lineItem;
            }

            $order['lineItems']['pageInfo'] = $connection['pageInfo'];
            $pages++;
        }

        return $order;
    }

    /**
     * Replaces a product node's `variants` connection with the flat list of every variant.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public function completeProductVariants(Store $store, array $product): array
    {
        $variants = $product['variants'] ?? null;

        if (! is_array($variants) || ! is_array($variants['nodes'] ?? null) || ! is_array($variants['pageInfo'] ?? null)) {
            throw new ShopifyGraphqlException([], 'Shopify product variants returned an unexpected response shape.');
        }

        $nodes = $variants['nodes'];
        $pages = 1;

        while (($variants['pageInfo']['hasNextPage'] ?? null) === true) {
            if ($pages >= 100 || ! is_string($product['id'] ?? null) || ! is_string($variants['pageInfo']['endCursor'] ?? null) || $variants['pageInfo']['endCursor'] === '') {
                throw new ShopifyGraphqlException([], 'Shopify product variant pagination could not be completed.');
            }

            $cursor = $variants['pageInfo']['endCursor'];
            $response = $this->transport->graphql($store, ShopifyQueries::get('ProductCompletenessVariants'), ['id' => $product['id'], 'after' => $cursor]);
            $next = $response['data']['product']['variants'] ?? null;

            if (! is_array($next) || ! is_array($next['nodes'] ?? null) || ! is_array($next['pageInfo'] ?? null) || (($next['pageInfo']['hasNextPage'] ?? null) === true && ($next['pageInfo']['endCursor'] ?? null) === $cursor)) {
                throw new ShopifyGraphqlException([], 'Shopify product variants returned an unexpected response shape.');
            }

            array_push($nodes, ...$next['nodes']);
            $variants = $next;
            $pages++;
        }

        $product['variants'] = $nodes;

        return $product;
    }

    private function isValidLineItemConnection(mixed $connection): bool
    {
        if (! is_array($connection)
            || ! is_array($connection['nodes'] ?? null)
            || ! is_array($connection['pageInfo'] ?? null)
            || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
            return false;
        }

        return array_all($connection['nodes'], fn (mixed $node): bool => is_array($node));
    }
}
