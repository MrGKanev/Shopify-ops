<?php
declare(strict_types=1);

use GuzzleHttp\HandlerStack;

require_once __DIR__ . '/ShopifyGraphQLClient.php';

/**
 * Shopify Admin API client.
 *
 * Uses Admin GraphQL for migrated resources and keeps legacy REST-shaped
 * arrays at the public method boundary for the rest of the app.
 */
class Shopify
{
    private const int PAGE_SIZE = 250; // max allowed by Shopify
    public const string API_VERSION = '2026-04';

    private readonly ?Cache $cache;
    private readonly ShopifyGraphQLClient $graphqlClient;

    public function __construct(
        string $store,
        string $accessToken,
        ?Cache $cache = null,
        ?HandlerStack $stack = null
    ) {
        $host = str_contains($store, '.') ? $store : "{$store}.myshopify.com";
        $baseUrl = "https://{$host}/admin/api/" . self::API_VERSION;

        $this->cache         = $cache;
        $this->graphqlClient = new ShopifyGraphQLClient($baseUrl, $accessToken, $stack);
    }

    // ── Public ────────────────────────────────────────────────────────

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

            echo "  Fetching Shopify orders";

            $this->paginateGraphQLVariables(
                $query,
                'orders',
                ['query' => $queryStr],
                function (array $edges) use (&$all) {
                    foreach ($edges as $edge) {
                        $all[] = $this->normalizeGraphQLOrder($edge['node'] ?? []);
                    }
                    echo '.';
                },
                1000
            );

            echo " done (" . count($all) . " orders)\n";
            return $all;
        };

        if ($this->cache) {
            return $this->cache->remember('shopify', "{$startDate}|{$endDate}", function () use ($fetch, $startDate, $endDate) {
                $orders = $fetch();
                echo "  [cache] Shopify orders stored ({$startDate} → {$endDate})\n";
                return $orders;
            });
        }

        return $fetch();
    }

    /**
     * Look up orders by order number (the short numeric part, e.g. "65075").
     * Tries both the plain number and the #1xxxxx name format.
     *
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

        $data  = $this->graphql($query, ['query' => "name:{$clean}"]);
        $edges = $data['data']['orders']['edges'] ?? [];
        return array_map(fn($edge) => $this->normalizeGraphQLOrder($edge['node'] ?? []), $edges);
    }

    /**
     * Fetches a single order by its Shopify numeric ID for detail views and ShipStation push.
     *
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

        $data = $this->graphql($query, ['id' => self::orderGid($orderId)]);
        $node = $data['data']['order'] ?? null;
        return is_array($node) ? $this->normalizeGraphQLOrder($node) : [];
    }

    /**
     * Returns true if any fulfillment order for this Shopify order ID has
     * status ON_HOLD. Hold state is exposed through fulfillment orders rather
     * than the order object's fulfillment status.
     *
     * Results are cached per order ID to avoid redundant calls during
     * large historical audits.
     */
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
                $data = $this->graphql($query, [
                    'id'    => self::orderGid($orderId),
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
     * Fetches all metafield definitions for a given owner type via GraphQL (default: ORDER).
     * REST API does not expose metafield_definitions - GraphQL is required.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchMetafieldDefinitions(string $ownerType = 'ORDER'): array
    {
        $query = <<<GQL
        {
          metafieldDefinitions(first: 250, ownerType: {$ownerType}) {
            edges {
              node {
                namespace
                key
                name
                description
                type { name }
              }
            }
          }
        }
        GQL;

        $data  = $this->graphql($query);
        $edges = $data['data']['metafieldDefinitions']['edges'] ?? [];
        return array_map(fn($e) => $e['node'], $edges);
    }

    /**
     * Fetches all metafields for a specific order by its Shopify numeric ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrderMetafields(string $orderId): array
    {
        $query = <<<'GQL'
        query GetOrderMetafields($id: ID!, $after: String) {
          order(id: $id) {
            metafields(first: 250, after: $after) {
              pageInfo { hasNextPage endCursor }
              nodes {
                id
                namespace
                key
                value
                type
                createdAt
                updatedAt
              }
            }
          }
        }
        GQL;

        $metafields = [];
        $cursor     = null;
        do {
            $data = $this->graphql($query, [
                'id'    => self::orderGid($orderId),
                'after' => $cursor,
            ]);

            $connection = $data['data']['order']['metafields'] ?? null;
            if (!is_array($connection)) {
                return $metafields;
            }

            foreach (($connection['nodes'] ?? []) as $node) {
                if (is_array($node)) {
                    $metafields[] = self::normalizeGraphQLMetafield($node, $orderId);
                }
            }

            $hasNext = (bool)($connection['pageInfo']['hasNextPage'] ?? false);
            $cursor  = $connection['pageInfo']['endCursor'] ?? null;
        } while ($hasNext && $cursor);

        return $metafields;
    }

    /**
     * Searches orders by Shopify tag via GraphQL. The `tag:` filter is natively
     * supported in the orders query string, so this is a fast indexed lookup.
     *
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

        ['truncated' => $truncated, 'pages' => $pages] = $this->paginateGraphQL(
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
     * Searches orders by metafield value by paginating through orders in a date
     * range and filtering client-side. Shopify does not support metafield value
     * filtering in the GraphQL query string, so we fetch each page with the
     * metafield inline and keep only matching rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchOrdersByMetafield(
        string $namespace,
        string $key,
        string $value,
        string $startDate = '',
        string $endDate   = '',
        int    $maxPages  = 10
    ): array {
        $ns = addslashes($namespace);
        $k  = addslashes($key);

        $dateFilter = '';
        if ($startDate) $dateFilter .= ' created_at:>=' . $startDate . 'T00:00:00Z';
        if ($endDate)   $dateFilter .= ' created_at:<=' . $endDate   . 'T23:59:59Z';
        $qFilter = trim($dateFilter) ? ', query: "' . trim($dateFilter) . '"' : '';

        $matches      = [];
        $totalScanned = 0;
        $totalWithMf  = 0;
        $sampleValues = [];

        $template = <<<GQL
        {
          orders(first: 250, sortKey: CREATED_AT, reverse: true{$qFilter}{{AFTER}}) {
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
                totalPriceSet { shopMoney { amount currencyCode } }
                metafield(namespace: "{$ns}", key: "{$k}") {
                  value
                  type
                }
              }
            }
          }
        }
        GQL;

        ['truncated' => $truncated, 'pages' => $pages] = $this->paginateGraphQL(
            $template,
            'orders',
            function (array $edges) use (&$matches, &$totalScanned, &$totalWithMf, &$sampleValues, $value) {
                foreach ($edges as $edge) {
                    $node    = $edge['node'];
                    $mfValue = $node['metafield']['value'] ?? null;
                    $totalScanned++;

                    if ($mfValue !== null) {
                        $totalWithMf++;
                        if (count($sampleValues) < 5 && !in_array($mfValue, $sampleValues, true)) {
                            $sampleValues[] = $mfValue;
                        }
                    }

                    if ($value === '') {
                        if ($mfValue !== null) $matches[] = $node;
                    } else {
                        if ($mfValue !== null && stripos($mfValue, $value) !== false) {
                            $matches[] = $node;
                        }
                    }
                }
            },
            $maxPages
        );

        return [
            'matches'       => $matches,
            'scanned'       => $totalScanned,
            'with_mf'       => $totalWithMf,
            'sample_values' => $sampleValues,
            'pages'         => $pages,
            'truncated'     => $truncated,
        ];
    }

    /**
     * Paginates through orders in a date range and returns aggregate tag statistics:
     * count per tag, last-seen order name and date. Used for the Tag Audit page.
     *
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

        ['truncated' => $truncated, 'pages' => $pages] = $this->paginateGraphQL(
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
     * Fetches paid orders in a date range with full shipping address fields for address validation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddressScan(string $startDate, string $endDate, bool $unfulfilledOnly = false): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::paidOrdersQuery($startDate, $endDate, $unfulfilledOnly),
            self::graphQLOrderCoreFields()
                . self::graphQLShippingAddressFields()
                . self::graphQLShippingLineFields()
        );
    }

    /**
     * Returns Shopify orders with refunded or partially_refunded financial status
     * in the given date range, including refund line details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForHighValue(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::paidOrdersQuery($startDate, $endDate, true),
            self::graphQLOrderCoreFields()
                . self::graphQLShippingAddressFields()
                . self::graphQLShippingLineFields()
        );
    }

    /**
     * Fetches orders whose shipping address was changed after the order was placed.
     * Strategy: paginate GraphQL order events in the window, filter for address-change
     * messages, then fetch the matching orders by ID.
     *
     * @return array<int, array<string, mixed>>  each entry has 'order' + 'changed_at'
     */
    public function fetchOrdersWithAddressChanges(string $startDate, string $endDate): array
    {
        $changed = [];
        foreach ($this->fetchGraphQLEventsByQuery(self::orderEventDateRangeQuery($startDate, $endDate)) as $ev) {
            if (!self::isAddressChangeEvent($ev)) {
                continue;
            }

            $id = (string)($ev['subject_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $ts = $ev['created_at'] ?? '';
            if (!isset($changed[$id]) || $ts > $changed[$id]) {
                $changed[$id] = $ts;
            }
        }

        if (empty($changed)) return [];

        $ordersById = $this->fetchGraphQLOrdersByIds(
            array_keys($changed),
            self::graphQLOrderCoreFields()
                . self::graphQLShippingAddressFields()
        );

        $orders = [];
        foreach ($ordersById as $id => $order) {
            if (!isset($changed[$id])) {
                continue;
            }

            $orders[] = [
                'order'      => $order,
                'changed_at' => $changed[$id],
            ];
        }

        usort($orders, fn($a, $b) => strcmp($b['changed_at'], $a['changed_at']));

        return $orders;
    }

    /**
     * Finds orders that had content edits (line items, notes, custom attributes, discounts)
     * after placement, using Shopify order events. Returns orders sorted by edit date desc.
     *
     * Each entry: shopify_id, order_number, created_at, edited_at, diff_mins,
     *             email, total, financial, fulfillment, edit_summary (string[])
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchEditedOrders(string $startDate, string $endDate): array
    {
        $byOrder = [];
        foreach ($this->fetchGraphQLEventsByQuery(self::orderEventDateRangeQuery($startDate, $endDate)) as $ev) {
            if (!self::isOrderEditEvent($ev)) {
                continue;
            }

            $id = (string)($ev['subject_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $ts = $ev['created_at'] ?? '';
            if (!isset($byOrder[$id])) {
                $byOrder[$id] = ['latest_at' => $ts, 'summary' => []];
            } elseif ($ts > $byOrder[$id]['latest_at']) {
                $byOrder[$id]['latest_at'] = $ts;
            }

            $short = ucfirst((string)($ev['message'] ?? ''));
            if ($short !== '' && count($byOrder[$id]['summary']) < 4 && !in_array($short, $byOrder[$id]['summary'], true)) {
                $byOrder[$id]['summary'][] = $short;
            }
        }

        if (empty($byOrder)) return [];

        $rows = [];
        $ordersById = $this->fetchGraphQLOrdersByIds(
            array_keys($byOrder),
            self::graphQLOrderCoreFields()
        );

        foreach ($ordersById as $oid => $o) {
            $ev        = $byOrder[$oid] ?? [];
            $createdTs = strtotime($o['created_at'] ?? '');
            $editedTs  = strtotime($ev['latest_at']  ?? '');
            $diffMins  = ($createdTs && $editedTs) ? max(0, (int)(($editedTs - $createdTs) / 60)) : 0;
            $rows[] = [
                'shopify_id'   => (string)$oid,
                'order_number' => $o['name']                ?? '',
                'created_at'   => substr($o['created_at']   ?? '', 0, 10),
                'edited_at'    => substr($ev['latest_at']   ?? '', 0, 16),
                'diff_mins'    => $diffMins,
                'email'        => $o['email']               ?? '',
                'total'        => $o['total_price']         ?? '',
                'financial'    => $o['financial_status']    ?? '',
                'fulfillment'  => $o['fulfillment_status']  ?? '',
                'edit_summary' => $ev['summary']            ?? [],
            ];
        }

        usort($rows, fn($a, $b) => strcmp($b['edited_at'], $a['edited_at']));
        return $rows;
    }

    public function fetchRefundedOrders(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::refundedOrdersQuery($startDate, $endDate),
            self::graphQLOrderCoreFields()
                . self::graphQLRefundFields(),
            fn(array $node) => in_array(
                self::normalizeFinancialStatus($node['displayFinancialStatus'] ?? null),
                ['refunded', 'partially_refunded'],
                true
            )
        );
    }

    /**
     * Returns all orders for a given customer email, plus customer summary data.
     * Uses GraphQL email: filter (indexed, fast).
     *
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

        ['truncated' => $truncated] = $this->paginateGraphQL(
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
     * Finds potential duplicate orders: same email + same total within 10 minutes.
     * Paginates through the given date range via GraphQL.
     *
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

        ['truncated' => $truncated] = $this->paginateGraphQL(
            $template,
            'orders',
            function (array $edges) use (&$all) {
                foreach ($edges as $e) $all[] = $e['node'];
            },
            40
        );

        // Group by email + amount, then find pairs within 10 minutes
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

    /**
     * Fetches the event/audit log for a specific order.
     *
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
            $data = $this->graphql($query, [
                'id'    => self::orderGid($orderId),
                'after' => $cursor,
            ]);

            $connection = $data['data']['order']['events'] ?? null;
            if (!is_array($connection)) {
                return $events;
            }

            foreach (($connection['edges'] ?? []) as $edge) {
                $node = $edge['node'] ?? null;
                if (is_array($node)) {
                    $events[] = self::normalizeGraphQLEvent($node, $orderId);
                }
            }

            $hasNext = (bool)($connection['pageInfo']['hasNextPage'] ?? false);
            $cursor  = $connection['pageInfo']['endCursor'] ?? null;
        } while ($hasNext && $cursor);

        return $events;
    }

    /**
     * Paid orders where billing country != shipping country.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForCountryMismatch(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::paidOrdersQuery($startDate, $endDate),
            self::graphQLOrderCoreFields()
                . self::graphQLBillingAddressFields()
                . self::graphQLShippingAddressFields()
        );
    }

    /**
     * Open orders in 'partial' fulfillment status - includes line_items + fulfillments
     * so callers can determine which items remain unfulfilled and for how long.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchPartiallyFulfilledOrders(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::partiallyFulfilledOrdersQuery($startDate, $endDate),
            self::graphQLOrderCoreFields()
                . self::graphQLLineItemFields()
                . self::graphQLFulfillmentFields(),
            fn(array $node) => self::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null) === 'partial'
        );
    }

    /**
     * Fetches all products from the store. $status can be 'active', 'draft', 'archived', or 'any'.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllProducts(string $status = 'active'): array
    {
        $all      = [];
        $queryArg = $this->productStatusGraphQLArg($status);
        $template = <<<GQL
        {
          products(first: 250{$queryArg}{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                title
                status
                descriptionHtml
                vendor
                productType
                mediaCount { count }
                variants(first: 250) {
                  edges {
                    node {
                      id
                      legacyResourceId
                      title
                      sku
                      barcode
                      inventoryQuantity
                      inventoryPolicy
                      inventoryItem { tracked }
                    }
                  }
                }
              }
            }
          }
        }
        GQL;

        $this->paginateGraphQL(
            $template,
            'products',
            function (array $edges) use (&$all) {
                foreach ($edges as $edge) {
                    $all[] = $this->normalizeGraphQLProduct($edge['node'] ?? []);
                }
            },
            1000
        );

        return $all;
    }

    /**
     * Fetches on-hold fulfillment orders via GraphQL, filtered to the given order creation date range.
     * Requires the read_merchant_managed_fulfillment_orders or read_assigned_fulfillment_orders scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOnHoldFulfillmentOrders(string $startDate, string $endDate): array
    {
        $all      = [];
        $template = <<<'GQL'
        {
          fulfillmentOrders(first: 250, query: "status:on_hold"{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                status
                order {
                  id
                  legacyResourceId
                  name
                  email
                  createdAt
                  displayFinancialStatus
                  displayFulfillmentStatus
                  totalPriceSet { shopMoney { amount } }
                }
                fulfillmentHolds {
                  reason
                  reasonNotes
                }
              }
            }
          }
        }
        GQL;

        $this->paginateGraphQL($template, 'fulfillmentOrders', function (array $edges) use (&$all, $startDate, $endDate) {
            foreach ($edges as $e) {
                $node      = $e['node'];
                $orderDate = substr($node['order']['createdAt'] ?? '', 0, 10);
                if ($orderDate >= $startDate && $orderDate <= $endDate) {
                    $all[] = $node;
                }
            }
        }, 40);

        return $all;
    }

    /**
     * Fetches fulfilled or partially-fulfilled orders with their fulfillment records (tracking data).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchFulfilledOrdersWithTracking(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::fulfilledOrPartialOrdersQuery($startDate, $endDate),
            self::graphQLOrderCoreFields()
                . self::graphQLFulfillmentFields(),
            fn(array $node) => in_array(
                self::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null),
                ['fulfilled', 'partial'],
                true
            )
        );
    }

    /**
     * Returns orders where the shipping address was changed AFTER the first fulfillment was created.
     * Builds on the same GraphQL events strategy as fetchOrdersWithAddressChanges but includes
     * fulfillments in the batch order fetch to compare timestamps.
     *
     * @return array<int, array{order: array, changed_at: string, fulfillment_at: string}>
     */
    public function fetchPostShipAddressChanges(string $startDate, string $endDate): array
    {
        $changed = [];
        foreach ($this->fetchGraphQLEventsByQuery(self::orderEventDateRangeQuery($startDate, $endDate)) as $ev) {
            if (!self::isAddressChangeEvent($ev)) {
                continue;
            }

            $id = (string)($ev['subject_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $ts = $ev['created_at'] ?? '';
            if (!isset($changed[$id]) || $ts > $changed[$id]) {
                $changed[$id] = $ts;
            }
        }

        if (empty($changed)) return [];

        $orders = [];
        $ordersById = $this->fetchGraphQLOrdersByIds(
            array_keys($changed),
            self::graphQLOrderCoreFields()
                . self::graphQLShippingAddressFields()
                . self::graphQLFulfillmentFields()
        );

        foreach ($ordersById as $oid => $o) {
            $changedAt = $changed[$oid] ?? '';

            $firstFulfillAt = '';
            foreach ($o['fulfillments'] ?? [] as $f) {
                $fa = $f['created_at'] ?? '';
                if ($fa && (!$firstFulfillAt || $fa < $firstFulfillAt)) {
                    $firstFulfillAt = $fa;
                }
            }

            // Only flag if address changed AFTER first fulfillment
            if (!$firstFulfillAt || $changedAt <= $firstFulfillAt) continue;

            $orders[] = [
                'order'          => $o,
                'changed_at'     => $changedAt,
                'fulfillment_at' => $firstFulfillAt,
            ];
        }

        usort($orders, fn($a, $b) => strcmp($b['changed_at'], $a['changed_at']));
        return $orders;
    }

    /**
     * Fetches paid, unfulfilled orders including the note field for keyword scanning.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersWithNotes(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::paidOrdersQuery($startDate, $endDate, true),
            self::graphQLOrderCoreFields()
                . self::graphQLOrderNoteFields()
        );
    }

    /**
     * Fetches paid orders with shipping address data for duplicate-address analysis.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddrDupes(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::paidOrdersQuery($startDate, $endDate),
            self::graphQLOrderCoreFields()
                . self::graphQLShippingAddressFields()
        );
    }

    /**
     * Fetches paid orders with shipping method, destination, and fulfillment data for SLA checks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForSla(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::paidOrdersQuery($startDate, $endDate),
            self::graphQLOrderCoreFields()
                . self::graphQLShippingAddressFields()
                . self::graphQLShippingLineFields()
                . self::graphQLLineItemFields()
                . self::graphQLFulfillmentFields()
        );
    }

    /**
     * Fetches cancelled Shopify orders in a date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCancelledOrders(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::orderDateRangeQuery($startDate, $endDate),
            self::graphQLOrderCoreFields()
                . self::graphQLOrderCancelReasonFields(),
            fn(array $node) => !empty($node['cancelledAt'])
        );
    }

    /**
     * Fetches paid orders with discount and shipping address fields for abuse clustering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForDiscountAudit(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::paidOrdersQuery($startDate, $endDate),
            self::graphQLOrderCoreFields()
                . self::graphQLShippingAddressFields()
                . self::graphQLDiscountApplicationFields()
        );
    }

    /**
     * Fetches paid orders with tags for policy validation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForTagPolicy(string $startDate, string $endDate): array
    {
        return $this->fetchGraphQLOrdersByQuery(
            self::paidOrdersQuery($startDate, $endDate),
            self::graphQLOrderCoreFields()
                . self::graphQLOrderTagFields()
        );
    }

    // ── Private ───────────────────────────────────────────────────────

    /**
     * Executes a GraphQL query against the Shopify Admin API.
     *
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables = []): array
    {
        return $this->graphqlClient->graphql($query, $variables);
    }

    private static function orderGid(string $orderId): string
    {
        $trimmed = trim($orderId);
        if (str_starts_with($trimmed, 'gid://shopify/Order/')) {
            return $trimmed;
        }

        if (!ctype_digit($trimmed)) {
            throw new InvalidArgumentException("Unsupported Shopify order ID: {$orderId}");
        }

        return "gid://shopify/Order/{$trimmed}";
    }

    /**
     * Maps Admin GraphQL Order nodes into the legacy REST order shape used by the UI and ShipStation push.
     *
     * @return array<string, mixed>
     */
    private function normalizeGraphQLOrder(array $node): array
    {
        $id   = self::legacyId($node['legacyResourceId'] ?? null, $node['id'] ?? null);
        $name = (string)($node['name'] ?? '');

        $order = [
            'id'                   => $id,
            'order_number'         => self::orderNumberFromName($name),
            'name'                 => $name,
            'created_at'           => $node['createdAt'] ?? '',
            'cancelled_at'         => $node['cancelledAt'] ?? null,
            'email'                => $node['email'] ?? '',
            'financial_status'     => self::normalizeFinancialStatus($node['displayFinancialStatus'] ?? null),
            'fulfillment_status'   => self::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null),
            'total_price'          => $node['totalPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $node['id'] ?? '',
        ];

        if (array_key_exists('totalTaxSet', $node)) {
            $order['total_tax'] = $node['totalTaxSet']['shopMoney']['amount'] ?? '0.00';
        }
        if (array_key_exists('cancelReason', $node)) {
            $reason = $node['cancelReason'] ?? null;
            $order['cancel_reason'] = $reason === null ? null : strtolower((string)$reason);
        }
        if (array_key_exists('note', $node)) {
            $order['note'] = $node['note'] ?? '';
        }
        if (array_key_exists('tags', $node)) {
            $order['tags'] = implode(', ', (array)($node['tags'] ?? []));
        }
        if (array_key_exists('shippingAddress', $node)) {
            $order['shipping_address'] = self::normalizeGraphQLAddress($node['shippingAddress'] ?? null);
        }
        if (array_key_exists('billingAddress', $node)) {
            $order['billing_address'] = self::normalizeGraphQLAddress($node['billingAddress'] ?? null);
        }
        if (isset($node['lineItems']['nodes'])) {
            $order['line_items'] = array_map(
                fn($lineItem) => self::normalizeGraphQLLineItem($lineItem),
                $node['lineItems']['nodes']
            );
        }
        if (isset($node['shippingLines']['nodes'])) {
            $order['shipping_lines'] = array_map(
                fn($shippingLine) => self::normalizeGraphQLShippingLine($shippingLine),
                $node['shippingLines']['nodes']
            );
        }
        if (isset($node['fulfillments'])) {
            $order['fulfillments'] = array_map(
                fn($fulfillment) => self::normalizeGraphQLFulfillment($fulfillment),
                (array)$node['fulfillments']
            );
        }
        if (isset($node['refunds'])) {
            $order['refunds'] = array_map(
                fn($refund) => self::normalizeGraphQLRefund($refund),
                (array)$node['refunds']
            );
        }
        if (isset($node['discountApplications']['nodes'])) {
            $order['discount_codes'] = array_values(array_filter(array_map(
                fn($discount) => self::normalizeGraphQLDiscountCode($discount),
                $node['discountApplications']['nodes']
            )));
        }

        return $order;
    }

    private static function orderDateRangeQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:any',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    private static function paidOrdersQuery(string $startDate, string $endDate, bool $unfulfilledOnly = false): string
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

    private static function refundedOrdersQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:any',
            '(financial_status:refunded OR financial_status:partially_refunded)',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    private static function partiallyFulfilledOrdersQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:open',
            'fulfillment_status:partial',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    private static function fulfilledOrPartialOrdersQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:any',
            '(fulfillment_status:fulfilled OR fulfillment_status:partial)',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    private static function graphQLOrderCoreFields(): string
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

    private static function graphQLShippingAddressFields(): string
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

    private static function graphQLBillingAddressFields(): string
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

    private static function graphQLShippingLineFields(): string
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

    private static function graphQLLineItemFields(): string
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

    private static function graphQLFulfillmentFields(): string
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

    private static function graphQLRefundFields(): string
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

    private static function graphQLDiscountApplicationFields(): string
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

    private static function graphQLOrderNoteFields(): string
    {
        return <<<'GQL'
                note

GQL;
    }

    private static function graphQLOrderTagFields(): string
    {
        return <<<'GQL'
                tags

GQL;
    }

    private static function graphQLOrderCancelReasonFields(): string
    {
        return <<<'GQL'
                cancelReason

GQL;
    }

    private static function graphQLEventFields(): string
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

    private static function orderEventDateRangeQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'subject_type:ORDER',
            'comments:false',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchGraphQLEventsByQuery(string $queryStr, int $maxPages = 1000): array
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
        $query = str_replace('{{EVENT_FIELDS}}', self::graphQLEventFields(), $query);

        $this->paginateGraphQLVariables(
            $query,
            'events',
            ['query' => $queryStr],
            function (array $edges) use (&$events) {
                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? null;
                    if (is_array($node)) {
                        $events[] = self::normalizeGraphQLEvent($node);
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
    private function fetchGraphQLOrdersByIds(array $orderIds, string $nodeFields): array
    {
        $ids = [];
        foreach ($orderIds as $orderId) {
            $id = (string)$orderId;
            if ($id === '') {
                continue;
            }
            $ids[] = self::orderGid($id);
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
            $data = $this->graphql($query, ['ids' => $chunk]);
            foreach (($data['data']['nodes'] ?? []) as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $order = $this->normalizeGraphQLOrder($node);
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
    private function fetchGraphQLOrdersByQuery(string $queryStr, string $nodeFields, ?callable $nodeFilter = null): array
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

        $this->paginateGraphQLVariables(
            $query,
            'orders',
            ['query' => $queryStr],
            function (array $edges) use (&$all, $nodeFilter) {
                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    if ($nodeFilter !== null && !$nodeFilter($node)) {
                        continue;
                    }
                    $all[] = $this->normalizeGraphQLOrder($node);
                }
            },
            1000
        );

        return $all;
    }

    private static function orderNumberFromName(string $name): int|string
    {
        $number = ltrim(trim($name), '#');
        return ctype_digit($number) ? (int)$number : $number;
    }

    private static function normalizeFinancialStatus(mixed $status): string
    {
        return strtolower((string)($status ?? ''));
    }

    private static function normalizeFulfillmentStatus(mixed $status): ?string
    {
        $normalized = strtolower((string)($status ?? ''));
        return match ($normalized) {
            '', 'unfulfilled' => null,
            'partially_fulfilled' => 'partial',
            default => $normalized,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeGraphQLAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'first_name'    => $address['firstName'] ?? '',
            'last_name'     => $address['lastName'] ?? '',
            'name'          => $address['name'] ?? '',
            'company'       => $address['company'] ?? null,
            'address1'      => $address['address1'] ?? '',
            'address2'      => $address['address2'] ?? '',
            'city'          => $address['city'] ?? '',
            'province'      => $address['province'] ?? '',
            'province_code' => $address['provinceCode'] ?? '',
            'country'       => $address['country'] ?? '',
            'country_code'  => $address['countryCodeV2'] ?? '',
            'zip'           => $address['zip'] ?? '',
            'phone'         => $address['phone'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeGraphQLLineItem(array $lineItem): array
    {
        $normalized = [
            'id'                   => self::legacyId(null, $lineItem['id'] ?? null),
            'title'                => $lineItem['title'] ?? $lineItem['name'] ?? '',
            'name'                 => $lineItem['name'] ?? $lineItem['title'] ?? '',
            'sku'                  => $lineItem['sku'] ?? '',
            'quantity'             => (int)($lineItem['quantity'] ?? 0),
            'variant_title'        => $lineItem['variantTitle'] ?? null,
            'price'                => $lineItem['originalUnitPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $lineItem['id'] ?? '',
        ];

        if (array_key_exists('unfulfilledQuantity', $lineItem)) {
            $normalized['fulfillable_quantity'] = (int)($lineItem['unfulfilledQuantity'] ?? 0);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeGraphQLShippingLine(array $shippingLine): array
    {
        return [
            'id'                   => self::legacyId(null, $shippingLine['id'] ?? null),
            'title'                => $shippingLine['title'] ?? '',
            'code'                 => $shippingLine['code'] ?? '',
            'price'                => $shippingLine['originalPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $shippingLine['id'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeGraphQLFulfillment(array $fulfillment): array
    {
        $trackingInfo = array_values(array_filter(
            (array)($fulfillment['trackingInfo'] ?? []),
            fn($tracking) => is_array($tracking)
        ));
        $firstTracking = $trackingInfo[0] ?? [];

        $lineItems = [];
        foreach (($fulfillment['fulfillmentLineItems']['edges'] ?? []) as $edge) {
            $node = $edge['node'] ?? [];
            if (!is_array($node)) {
                continue;
            }

            $lineItem = self::normalizeGraphQLLineItem((array)($node['lineItem'] ?? []));
            $lineItem['quantity'] = (int)($node['quantity'] ?? $lineItem['quantity'] ?? 0);
            $lineItems[] = $lineItem;
        }

        return [
            'id'                   => self::legacyId($fulfillment['legacyResourceId'] ?? null, $fulfillment['id'] ?? null),
            'admin_graphql_api_id' => $fulfillment['id'] ?? '',
            'created_at'           => $fulfillment['createdAt'] ?? '',
            'status'               => strtolower((string)($fulfillment['status'] ?? '')),
            'display_status'       => strtolower((string)($fulfillment['displayStatus'] ?? '')),
            'shipment_status'      => strtolower((string)($fulfillment['displayStatus'] ?? '')),
            'tracking_company'     => $firstTracking['company'] ?? '',
            'tracking_number'      => $firstTracking['number'] ?? '',
            'tracking_url'         => $firstTracking['url'] ?? '',
            'tracking_numbers'     => array_values(array_filter(array_map(fn($tracking) => $tracking['number'] ?? '', $trackingInfo))),
            'tracking_urls'        => array_values(array_filter(array_map(fn($tracking) => $tracking['url'] ?? '', $trackingInfo))),
            'line_items'           => $lineItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeGraphQLRefund(array $refund): array
    {
        $refundLineItems = [];
        foreach (($refund['refundLineItems']['nodes'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }

            $lineItem = (array)($node['lineItem'] ?? []);
            $refundLineItems[] = [
                'quantity'     => (int)($node['quantity'] ?? 0),
                'subtotal'     => $node['subtotalSet']['shopMoney']['amount'] ?? '0.00',
                'line_item_id' => self::legacyId(null, $lineItem['id'] ?? null),
                'line_item'    => self::normalizeGraphQLLineItem($lineItem),
            ];
        }

        $transactions = [];
        foreach (($refund['transactions']['nodes'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }

            $transactions[] = [
                'id'                   => self::legacyId(null, $node['id'] ?? null),
                'kind'                 => strtolower((string)($node['kind'] ?? '')),
                'status'               => strtolower((string)($node['status'] ?? '')),
                'amount'               => $node['amountSet']['shopMoney']['amount'] ?? '0.00',
                'admin_graphql_api_id' => $node['id'] ?? '',
            ];
        }

        return [
            'id'                   => self::legacyId($refund['legacyResourceId'] ?? null, $refund['id'] ?? null),
            'admin_graphql_api_id' => $refund['id'] ?? '',
            'created_at'           => $refund['createdAt'] ?? '',
            'note'                 => $refund['note'] ?? '',
            'total_refunded'       => $refund['totalRefundedSet']['shopMoney']['amount'] ?? '0.00',
            'refund_line_items'    => $refundLineItems,
            'transactions'         => $transactions,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeGraphQLDiscountCode(array $discount): ?array
    {
        if (($discount['__typename'] ?? '') !== 'DiscountCodeApplication') {
            return null;
        }

        $code = trim((string)($discount['code'] ?? ''));
        if ($code === '') {
            return null;
        }

        $value = (array)($discount['value'] ?? []);
        $type = match ($value['__typename'] ?? '') {
            'MoneyV2' => 'fixed_amount',
            'PricingPercentageValue' => 'percentage',
            default => strtolower((string)($value['__typename'] ?? '')),
        };

        return [
            'code'             => $code,
            'amount'           => $value['amount'] ?? (isset($value['percentage']) ? (string)$value['percentage'] : ''),
            'type'             => $type,
            'allocation_method' => strtolower((string)($discount['allocationMethod'] ?? '')),
            'target_selection' => strtolower((string)($discount['targetSelection'] ?? '')),
            'target_type'      => strtolower((string)($discount['targetType'] ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeGraphQLEvent(array $event, ?string $fallbackOrderId = null): array
    {
        $subjectGid = (string)($event['subjectId'] ?? '');
        if ($subjectGid === '' && $fallbackOrderId !== null) {
            $subjectGid = self::orderGid($fallbackOrderId);
        }

        $subjectId = $subjectGid !== '' ? self::legacyId(null, $subjectGid) : '';
        $action    = strtolower((string)($event['action'] ?? ''));

        return [
            'id'                   => self::legacyId(null, $event['id'] ?? null),
            'admin_graphql_api_id' => $event['id'] ?? '',
            'verb'                 => $action,
            'action'               => $action,
            'created_at'           => $event['createdAt'] ?? '',
            'message'              => (string)($event['message'] ?? ''),
            'subject_id'           => $subjectId,
            'subject_type'         => strtolower((string)($event['subjectType'] ?? 'Order')),
            'subject_graphql_api_id' => $subjectGid,
            'app_title'            => $event['appTitle'] ?? '',
        ];
    }

    private static function isAddressChangeEvent(array $event): bool
    {
        $haystack = strtolower(trim(
            (string)($event['verb'] ?? '') . ' ' .
            (string)($event['action'] ?? '') . ' ' .
            (string)($event['message'] ?? '')
        ));

        return str_contains($haystack, 'shipping address')
            || str_contains($haystack, 'address was')
            || str_contains($haystack, 'shipping_address');
    }

    private static function isOrderEditEvent(array $event): bool
    {
        $verb = strtolower((string)($event['verb'] ?? $event['action'] ?? ''));
        $msg  = strtolower((string)($event['message'] ?? ''));

        return $verb === 'edit_complete'
            || str_contains($msg, 'was edited')
            || str_contains($msg, 'were edited')
            || str_contains($msg, 'item was added')
            || str_contains($msg, 'item was removed')
            || str_contains($msg, 'discount was added')
            || str_contains($msg, 'discount was removed')
            || str_contains($msg, 'note was updated')
            || str_contains($msg, 'custom attributes');
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeGraphQLMetafield(array $metafield, string $ownerId): array
    {
        return [
            'id'                   => self::legacyId(null, $metafield['id'] ?? null),
            'namespace'            => $metafield['namespace'] ?? '',
            'key'                  => $metafield['key'] ?? '',
            'value'                => $metafield['value'] ?? '',
            'type'                 => $metafield['type'] ?? '',
            'owner_id'             => self::legacyId(null, self::orderGid($ownerId)),
            'owner_resource'       => 'order',
            'created_at'           => $metafield['createdAt'] ?? '',
            'updated_at'           => $metafield['updatedAt'] ?? '',
            'admin_graphql_api_id' => $metafield['id'] ?? '',
        ];
    }

    private function productStatusGraphQLArg(string $status): string
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

    /**
     * Maps Admin GraphQL Product nodes into the legacy REST product shape used by the UI.
     *
     * @return array<string, mixed>
     */
    private function normalizeGraphQLProduct(array $node): array
    {
        $productId = self::legacyId($node['legacyResourceId'] ?? null, $node['id'] ?? null);
        $images    = array_fill(0, max(0, (int)($node['mediaCount']['count'] ?? 0)), []);

        $variants = [];
        foreach (($node['variants']['edges'] ?? []) as $edge) {
            $variant = $edge['node'] ?? [];
            $variants[] = [
                'id'                   => self::legacyId($variant['legacyResourceId'] ?? null, $variant['id'] ?? null),
                'product_id'           => $productId,
                'title'                => $variant['title'] ?? '',
                'sku'                  => $variant['sku'] ?? '',
                'barcode'              => $variant['barcode'] ?? null,
                'inventory_quantity'   => (int)($variant['inventoryQuantity'] ?? 0),
                'inventory_policy'     => strtolower((string)($variant['inventoryPolicy'] ?? '')),
                'inventory_management' => ($variant['inventoryItem']['tracked'] ?? false) ? 'shopify' : null,
                'admin_graphql_api_id' => $variant['id'] ?? '',
            ];
        }

        return [
            'id'                   => $productId,
            'title'                => $node['title'] ?? '',
            'status'               => strtolower((string)($node['status'] ?? '')),
            'body_html'            => $node['descriptionHtml'] ?? '',
            'vendor'               => $node['vendor'] ?? '',
            'product_type'         => $node['productType'] ?? '',
            'images'               => $images,
            'variants'             => $variants,
            'admin_graphql_api_id' => $node['id'] ?? '',
        ];
    }

    private static function legacyId(mixed $legacyResourceId, mixed $gid): int|string
    {
        $id = (string)($legacyResourceId ?? '');
        if ($id === '' && is_string($gid) && preg_match('~/(\d+)(?:\?.*)?$~', $gid, $matches)) {
            $id = $matches[1];
        }

        return ctype_digit($id) ? (int)$id : $id;
    }

    /**
     * Runs a paginated GraphQL query, calling $processor with each page's edges.
     * The query template must contain {{AFTER}} where the cursor argument goes
     * (e.g. `orders(first: 250, query: "..."{{AFTER}}) {`).
     *
     * @param  callable(array $edges): void $processor
     * @return array{truncated: bool, pages: int}
     */
    private function paginateGraphQL(
        string   $queryTemplate,
        string   $rootKey,
        callable $processor,
        int      $maxPages = 20
    ): array {
        return $this->graphqlClient->paginateGraphQL($queryTemplate, $rootKey, $processor, $maxPages);
    }

    /**
     * Runs a paginated GraphQL query that uses an `$after` variable.
     *
     * @param  callable(array $edges): void $processor
     * @return array{truncated: bool, pages: int}
     */
    private function paginateGraphQLVariables(
        string   $query,
        string   $rootKey,
        array    $variables,
        callable $processor,
        int      $maxPages = 20
    ): array {
        return $this->graphqlClient->paginateGraphQLVariables($query, $rootKey, $variables, $processor, $maxPages);
    }
}
