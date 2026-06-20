<?php
declare(strict_types=1);

/**
 * Order search, tagging, customer, and duplicate-order insights.
 */
class ShopifyOrderInsights
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    /**
     * @return array{matches: array, scanned: int, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(
        string $tag,
        string $startDate = '',
        string $endDate   = '',
        int    $maxPages  = 20
    ): array {
        $safeTag    = addslashes($tag);
        $dateFilter = '';
        if ($startDate) $dateFilter .= ' created_at:>=' . $startDate . 'T00:00:00Z';
        if ($endDate)   $dateFilter .= ' created_at:<=' . $endDate   . 'T23:59:59Z';
        $queryStr = 'tag:"' . $safeTag . '"' . ($dateFilter ? ' ' . trim($dateFilter) : '');

        $matches  = [];
        $template = <<<GQL
        {
          orders(first: 250, sortKey: CREATED_AT, reverse: true, query: "{$queryStr}"{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                name
                displayFinancialStatus
                displayFulfillmentStatus
                createdAt
                email
                tags
                totalPriceSet { shopMoney { amount currencyCode } }
              }
            }
          }
        }
        GQL;

        ['truncated' => $truncated, 'pages' => $pages] = $this->client->paginateGraphQL(
            $template,
            'orders',
            function (array $edges) use (&$matches) {
                foreach ($edges as $e) $matches[] = $e['node'];
            },
            $maxPages
        );

        return [
            'matches'   => $matches,
            'scanned'   => count($matches),
            'pages'     => $pages,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array{tags: list<array>, total_orders: int, truncated: bool, pages: int}
     */
    public function fetchTagStats(string $startDate = '', string $endDate = '', int $maxPages = 40): array
    {
        $dateFilter = '';
        if ($startDate) $dateFilter .= ' created_at:>=' . $startDate . 'T00:00:00Z';
        if ($endDate)   $dateFilter .= ' created_at:<=' . $endDate   . 'T23:59:59Z';
        $qFilter = trim($dateFilter) ? ', query: "' . trim($dateFilter) . '"' : '';

        $tagCounts    = [];
        $tagLastOrder = [];
        $totalOrders  = 0;

        $template = <<<GQL
        {
          orders(first: 250, sortKey: CREATED_AT, reverse: true{$qFilter}{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                name
                createdAt
                tags
              }
            }
          }
        }
        GQL;

        ['truncated' => $truncated, 'pages' => $pages] = $this->client->paginateGraphQL(
            $template,
            'orders',
            function (array $edges) use (&$tagCounts, &$tagLastOrder, &$totalOrders) {
                foreach ($edges as $e) {
                    $node = $e['node'];
                    $totalOrders++;
                    foreach ($node['tags'] as $tag) {
                        if ($tag === '') continue;
                        $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                        if (!isset($tagLastOrder[$tag]) || $node['createdAt'] > ($tagLastOrder[$tag]['date'] ?? '')) {
                            $tagLastOrder[$tag] = ['name' => $node['name'], 'date' => $node['createdAt']];
                        }
                    }
                }
            },
            $maxPages
        );

        arsort($tagCounts);

        $tags = [];
        foreach ($tagCounts as $tag => $count) {
            $tags[] = [
                'tag'        => $tag,
                'count'      => $count,
                'last_order' => $tagLastOrder[$tag]['name'] ?? null,
                'last_date'  => substr($tagLastOrder[$tag]['date'] ?? '', 0, 10),
            ];
        }

        return [
            'tags'         => $tags,
            'total_orders' => $totalOrders,
            'truncated'    => $truncated,
            'pages'        => $pages,
        ];
    }

    /**
     * @return array{orders: array, customer: array|null, total_spent: float, currency: string, truncated: bool}
     */
    public function lookupCustomer(string $email, int $maxPages = 20): array
    {
        $safeEmail = addslashes(strtolower(trim($email)));
        $orders    = [];
        $customer  = null;

        $template = <<<GQL
        {
          orders(first: 250, sortKey: CREATED_AT, reverse: true, query: "email:\"{$safeEmail}\""{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                name
                displayFinancialStatus
                displayFulfillmentStatus
                cancelledAt
                createdAt
                email
                tags
                totalPriceSet { shopMoney { amount currencyCode } }
                customer {
                  id
                  firstName
                  lastName
                  createdAt
                  verifiedEmail
                }
              }
            }
          }
        }
        GQL;

        ['truncated' => $truncated] = $this->client->paginateGraphQL(
            $template,
            'orders',
            function (array $edges) use (&$orders, &$customer) {
                foreach ($edges as $e) {
                    $node = $e['node'];
                    if ($customer === null && isset($node['customer'])) {
                        $customer = $node['customer'];
                    }
                    $orders[] = $node;
                }
            },
            $maxPages
        );

        $totalSpent = 0.0;
        $currency   = 'USD';
        foreach ($orders as $o) {
            $totalSpent += (float) ($o['totalPriceSet']['shopMoney']['amount'] ?? 0);
            $currency    = $o['totalPriceSet']['shopMoney']['currencyCode'] ?? $currency;
        }

        return compact('orders', 'customer', 'totalSpent', 'currency') + ['truncated' => $truncated];
    }

    /**
     * @return array{pairs: list<array>, scanned: int, truncated: bool}
     */
    public function findDuplicateOrders(string $startDate, string $endDate): array
    {
        $queryStr = 'created_at:>=' . $startDate . 'T00:00:00Z created_at:<=' . $endDate . 'T23:59:59Z';
        $all      = [];

        $template = <<<GQL
        {
          orders(first: 250, sortKey: CREATED_AT, reverse: false, query: "{$queryStr}"{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                name
                email
                createdAt
                displayFinancialStatus
                totalPriceSet { shopMoney { amount currencyCode } }
              }
            }
          }
        }
        GQL;

        ['truncated' => $truncated] = $this->client->paginateGraphQL(
            $template,
            'orders',
            function (array $edges) use (&$all) {
                foreach ($edges as $e) $all[] = $e['node'];
            },
            40
        );

        $groups = [];
        foreach ($all as $order) {
            $email  = strtolower(trim($order['email'] ?? ''));
            $amount = $order['totalPriceSet']['shopMoney']['amount'] ?? '0';
            if (!$email) continue;
            $groups[$email . '|' . $amount][] = $order;
        }

        $pairs = [];
        foreach ($groups as $orders) {
            if (count($orders) < 2) continue;
            for ($i = 0; $i < count($orders); $i++) {
                for ($j = $i + 1; $j < count($orders); $j++) {
                    $diff = abs(strtotime($orders[$i]['createdAt']) - strtotime($orders[$j]['createdAt']));
                    if ($diff <= 600) {
                        $pairs[] = [$orders[$i], $orders[$j]];
                    }
                }
            }
        }

        return ['pairs' => $pairs, 'scanned' => count($all), 'truncated' => $truncated];
    }
}
