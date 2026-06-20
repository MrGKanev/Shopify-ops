<?php
declare(strict_types=1);

use GuzzleHttp\HandlerStack;

require_once __DIR__ . '/ShopifyGraphQLClient.php';
require_once __DIR__ . '/ShopifyGraphQLNormalizer.php';
require_once __DIR__ . '/ShopifyGraphQLQueries.php';
require_once __DIR__ . '/ShopifyOrderFetcher.php';
require_once __DIR__ . '/ShopifyOrderAudits.php';

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
    private readonly ShopifyOrderFetcher $orderFetcher;
    private readonly ShopifyOrderAudits $orderAudits;

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
        $this->orderFetcher  = new ShopifyOrderFetcher($this->graphqlClient);
        $this->orderAudits   = new ShopifyOrderAudits($this->orderFetcher);
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
                        $all[] = ShopifyGraphQLNormalizer::normalizeOrder($edge['node'] ?? []);
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
        return array_map(fn($edge) => ShopifyGraphQLNormalizer::normalizeOrder($edge['node'] ?? []), $edges);
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

        $data = $this->graphql($query, ['id' => ShopifyGraphQLNormalizer::orderGid($orderId)]);
        $node = $data['data']['order'] ?? null;
        return is_array($node) ? ShopifyGraphQLNormalizer::normalizeOrder($node) : [];
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
                'id'    => ShopifyGraphQLNormalizer::orderGid($orderId),
                'after' => $cursor,
            ]);

            $connection = $data['data']['order']['metafields'] ?? null;
            if (!is_array($connection)) {
                return $metafields;
            }

            foreach (($connection['nodes'] ?? []) as $node) {
                if (is_array($node)) {
                    $metafields[] = ShopifyGraphQLNormalizer::normalizeMetafield($node, $orderId);
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
        return $this->orderAudits->fetchOrdersForAddressScan($startDate, $endDate, $unfulfilledOnly);
    }

    /**
     * Returns Shopify orders with refunded or partially_refunded financial status
     * in the given date range, including refund line details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForHighValue(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForHighValue($startDate, $endDate);
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
        return $this->orderAudits->fetchOrdersWithAddressChanges($startDate, $endDate);
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
        return $this->orderAudits->fetchEditedOrders($startDate, $endDate);
    }

    public function fetchRefundedOrders(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchRefundedOrders($startDate, $endDate);
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

    /**
     * Paid orders where billing country != shipping country.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForCountryMismatch(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForCountryMismatch($startDate, $endDate);
    }

    /**
     * Open orders in 'partial' fulfillment status - includes line_items + fulfillments
     * so callers can determine which items remain unfulfilled and for how long.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchPartiallyFulfilledOrders(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchPartiallyFulfilledOrders($startDate, $endDate);
    }

    /**
     * Fetches all products from the store. $status can be 'active', 'draft', 'archived', or 'any'.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllProducts(string $status = 'active'): array
    {
        $all      = [];
        $queryArg = ShopifyGraphQLQueries::productStatusGraphQLArg($status);
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
                    $all[] = ShopifyGraphQLNormalizer::normalizeProduct($edge['node'] ?? []);
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
        return $this->orderAudits->fetchFulfilledOrdersWithTracking($startDate, $endDate);
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
        return $this->orderAudits->fetchPostShipAddressChanges($startDate, $endDate);
    }

    /**
     * Fetches paid, unfulfilled orders including the note field for keyword scanning.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersWithNotes(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersWithNotes($startDate, $endDate);
    }

    /**
     * Fetches paid orders with shipping address data for duplicate-address analysis.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddrDupes(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForAddrDupes($startDate, $endDate);
    }

    /**
     * Fetches paid orders with shipping method, destination, and fulfillment data for SLA checks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForSla(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForSla($startDate, $endDate);
    }

    /**
     * Fetches cancelled Shopify orders in a date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCancelledOrders(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchCancelledOrders($startDate, $endDate);
    }

    /**
     * Fetches paid orders with discount and shipping address fields for abuse clustering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForDiscountAudit(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForDiscountAudit($startDate, $endDate);
    }

    /**
     * Fetches paid orders with tags for policy validation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForTagPolicy(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForTagPolicy($startDate, $endDate);
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
