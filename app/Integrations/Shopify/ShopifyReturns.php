<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Models\Store;
use Carbon\CarbonImmutable;

class ShopifyReturns
{
    public function __construct(private readonly ShopifyTransport $transport) {}

    /** @param list<string> $knownReturnIds
     * @return array{returns: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function candidates(Store $store, string $startDate, string $endDate, array $knownReturnIds = []): array
    {
        $end = CarbonImmutable::parse($endDate, $store->shopTimezone())->addDay()->startOfDay();
        $start = CarbonImmutable::parse($startDate, $store->shopTimezone())->startOfDay();
        $search = 'status:any (return_status:return_requested OR return_status:in_progress OR return_status:inspection_complete OR return_status:return_failed OR (return_status:returned AND -fulfillment_status:fulfilled)) created_at:<'.$end->toIso8601String();
        $orders = $this->transport->paginateGraphql($store, ShopifyQueries::get('ReturnExceptionOrders'), 'orders', ['search' => $search], 100);
        $returns = [];
        $seen = [];
        $pages = $orders['pages'];
        $truncated = $orders['truncated'];
        foreach ($orders['edges'] as $edge) {
            $order = $edge['node'] ?? null;
            if (! is_array($order) || ! is_string($order['id'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify returned invalid return orders.');
            }
            $headers = $this->connection($store, 'OrderReturns', 'order.returns', $order['id']);
            $pages += $headers['pages'];
            $truncated = $truncated || $headers['truncated'];
            foreach ($headers['nodes'] as $return) {
                if (! is_string($return['createdAt'] ?? null) || ! is_string($return['id'] ?? null)) {
                    throw new ShopifyGraphqlException([], 'Shopify returned an invalid return.');
                }
                if (isset($seen[$return['id']])) {
                    continue;
                }
                $created = CarbonImmutable::parse($return['createdAt']);
                if ($created->lt($start) || $created->gte($end)) {
                    continue;
                }
                $seen[$return['id']] = true;
                $loaded = $this->loadReturn($store, $return, $order);
                $pages += $loaded['pages'];
                $truncated = $truncated || $loaded['truncated'];
                if ($loaded['return'] !== null) {
                    $returns[] = $loaded['return'];
                }
            }
        }

        foreach (array_unique($knownReturnIds) as $id) {
            if (isset($seen[$id]) || preg_match('~\Agid://shopify/Return/[0-9]+\z~', $id) !== 1) {
                continue;
            }
            $snapshot = $this->transport->graphql($store, ShopifyQueries::get('ReturnExceptionSnapshot'), ['id' => $id]);
            $return = $snapshot['data']['return'] ?? null;
            if (! is_array($return) || ($return['id'] ?? null) !== $id || ! is_array($return['order'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify could not confirm a previously flagged return.');
            }
            $created = CarbonImmutable::parse($return['createdAt']);
            if ($created->lt($start) || $created->gte($end)) {
                continue;
            }
            $loaded = $this->loadReturn($store, $return, $return['order']);
            $pages += 1 + $loaded['pages'];
            $truncated = $truncated || $loaded['truncated'];
            if ($loaded['return'] !== null) {
                $returns[] = $loaded['return'];
            }
        }

        return ['returns' => $returns, 'pages' => $pages, 'truncated' => $truncated];
    }

    /** @param array<string, mixed> $return
     * @param array<string, mixed> $order
     * @return array{return: array<string, mixed>|null, pages: int, truncated: bool} */
    private function loadReturn(Store $store, array $return, array $order): array
    {
        $lines = $this->connection($store, 'ReturnExceptionLines', 'return.returnLineItems', $return['id']);
        $exchanges = $this->connection($store, 'ReturnExceptionExchanges', 'return.exchangeLineItems', $return['id']);
        $reverse = $this->connection($store, 'ReturnExceptionReverseOrders', 'return.reverseFulfillmentOrders', $return['id']);
        $complete = ! $lines['truncated'] && ! $exchanges['truncated'] && ! $reverse['truncated'];
        $pages = $lines['pages'] + $exchanges['pages'] + $reverse['pages'];
        $reverseLines = [];
        foreach ($reverse['nodes'] as $reverseOrder) {
            $items = $this->connection($store, 'ReturnExceptionDispositions', 'reverseFulfillmentOrder.lineItems', $reverseOrder['id']);
            $pages += $items['pages'];
            $complete = $complete && ! $items['truncated'];
            array_push($reverseLines, ...$items['nodes']);
        }

        return ['return' => $complete ? [...$return, 'order' => $order, 'return_lines' => $lines['nodes'], 'exchange_lines' => $exchanges['nodes'], 'reverse_lines' => $reverseLines] : null, 'pages' => $pages, 'truncated' => ! $complete];
    }

    /** @return array{nodes: list<array<string, mixed>>, pages: int, truncated: bool} */
    private function connection(Store $store, string $operation, string $path, string $id): array
    {
        $nodes = [];
        $cursor = null;
        $pages = 0;
        do {
            $result = $this->transport->graphql($store, ShopifyQueries::get($operation), ['id' => $id, 'after' => $cursor]);
            $connection = data_get($result, 'data.'.$path);
            if (! is_array($connection) || ! is_array($connection['nodes'] ?? null) || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify returned incomplete return data.');
            }
            foreach ($connection['nodes'] as $node) {
                if (! is_array($node)) {
                    throw new ShopifyGraphqlException([], 'Shopify returned invalid return data.');
                }
                $nodes[] = $node;
            }
            $hasNext = $connection['pageInfo']['hasNextPage'];
            $next = $connection['pageInfo']['endCursor'] ?? null;
            if ($hasNext && (! is_string($next) || $next === '' || $next === $cursor)) {
                throw new ShopifyGraphqlException([], 'Shopify returned invalid return pagination.');
            }
            $cursor = $next;
            $pages++;
        } while ($hasNext && $pages < 20);

        return ['nodes' => $nodes, 'pages' => $pages, 'truncated' => $hasNext];
    }
}
