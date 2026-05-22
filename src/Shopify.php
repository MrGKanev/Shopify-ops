<?php
/**
 * Shopify Admin REST API client.
 *
 * Fetches all orders in a date range using cursor-based pagination
 * (Link header) - required for stores with > 250 orders per page.
 *
 * API docs: https://shopify.dev/docs/api/admin-rest/latest/resources/order
 */
class Shopify
{
    private const int PAGE_SIZE = 250; // max allowed by Shopify

    private readonly string $baseUrl;
    private readonly string $token;
    private readonly ?Cache $cache;

    public function __construct(string $store, string $accessToken, ?Cache $cache = null)
    {
        $host = str_contains($store, '.') ? $store : "{$store}.myshopify.com";
        $this->baseUrl = "https://{$host}/admin/api/2025-04";
        $this->token   = $accessToken;
        $this->cache   = $cache;
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
            $all = [];

            $params = http_build_query([
                'status'         => 'any',
                'created_at_min' => $startDate . 'T00:00:00-00:00',
                'created_at_max' => $endDate   . 'T23:59:59-00:00',
                'limit'          => self::PAGE_SIZE,
                'fields'         => 'id,order_number,name,financial_status,fulfillment_status,cancelled_at,created_at,email,total_price,shipping_lines,line_items',
            ]);

            echo "  Fetching Shopify orders";

            $nextUrl = "{$this->baseUrl}/orders.json?{$params}";

            while ($nextUrl) {
                [$orders, $nextUrl] = $this->getPage($nextUrl);
                array_push($all, ...$orders);
                echo '.';
            }

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
        $params = http_build_query([
            'status' => 'any',
            'name'   => $clean,
            'limit'  => 10,
            'fields' => 'id,order_number,name,financial_status,fulfillment_status,cancelled_at,created_at,email,total_price',
        ]);
        $data = $this->get("{$this->baseUrl}/orders.json?{$params}");
        return $data['orders'] ?? [];
    }

    /**
     * Fetches a single order by its Shopify numeric ID (full detail).
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $url  = "{$this->baseUrl}/orders/{$orderId}.json";
        $data = $this->get($url);
        return $data['order'] ?? [];
    }

    /**
     * Returns true if any fulfillment order for this Shopify order ID has
     * status 'on_hold'. Uses the Fulfillment Orders API (separate endpoint
     * from orders.json - hold state is not exposed on the order object itself).
     *
     * Results are cached per order ID to avoid redundant calls during
     * large historical audits.
     */
    public function isOnHold(string $orderId): bool
    {
        $check = function () use ($orderId): bool {
            $url  = "{$this->baseUrl}/orders/{$orderId}/fulfillment_orders.json";
            $data = $this->get($url);

            foreach ($data['fulfillment_orders'] ?? [] as $fo) {
                if (($fo['status'] ?? '') === 'on_hold') {
                    return true;
                }
            }
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
        $data = $this->get("{$this->baseUrl}/orders/{$orderId}/metafields.json");
        return $data['metafields'] ?? [];
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

        $matches = [];
        $cursor  = null;
        $page    = 0;

        do {
            $after = $cursor ? ", after: \"{$cursor}\"" : '';
            $gql   = <<<GQL
            {
              orders(first: 250, sortKey: CREATED_AT, reverse: true, query: "{$queryStr}"{$after}) {
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

            $data    = $this->graphql($gql);
            $conn    = $data['data']['orders'] ?? [];
            $edges   = $conn['edges'] ?? [];
            foreach ($edges as $e) $matches[] = $e['node'];

            $hasNext = $conn['pageInfo']['hasNextPage'] ?? false;
            $cursor  = $conn['pageInfo']['endCursor']   ?? null;
            $page++;
        } while ($hasNext && $cursor && $page < $maxPages);

        return [
            'matches'   => $matches,
            'scanned'   => count($matches),
            'pages'     => $page,
            'truncated' => $hasNext,
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
        $ns    = addslashes($namespace);
        $k     = addslashes($key);

        $dateFilter = '';
        if ($startDate) {
            $dateFilter .= ' created_at:>=' . $startDate . 'T00:00:00Z';
        }
        if ($endDate) {
            $dateFilter .= ' created_at:<=' . $endDate . 'T23:59:59Z';
        }
        $queryStr = trim($dateFilter) ?: '';

        $matches      = [];
        $cursor       = null;
        $page         = 0;
        $totalScanned = 0;
        $totalWithMf  = 0;
        $sampleValues = []; // first 5 distinct non-null values seen

        do {
            $after   = $cursor ? ", after: \"{$cursor}\"" : '';
            $qFilter = $queryStr ? ", query: \"{$queryStr}\"" : '';

            $gql = <<<GQL
            {
              orders(first: 250, sortKey: CREATED_AT, reverse: true{$qFilter}{$after}) {
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

            $data  = $this->graphql($gql);
            $conn  = $data['data']['orders'] ?? [];
            $edges = $conn['edges'] ?? [];

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

            $hasNext = $conn['pageInfo']['hasNextPage'] ?? false;
            $cursor  = $conn['pageInfo']['endCursor']   ?? null;
            $page++;

        } while ($hasNext && $cursor && $page < $maxPages);

        return [
            'matches'      => $matches,
            'scanned'      => $totalScanned,
            'with_mf'      => $totalWithMf,
            'sample_values'=> $sampleValues,
            'pages'        => $page,
            'truncated'    => $hasNext,
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
        $queryStr = trim($dateFilter) ?: '';

        $tagCounts    = [];
        $tagLastOrder = [];
        $cursor       = null;
        $page         = 0;
        $totalOrders  = 0;
        $hasNext      = false;

        do {
            $after   = $cursor ? ", after: \"{$cursor}\"" : '';
            $qFilter = $queryStr ? ", query: \"{$queryStr}\"" : '';

            $gql = <<<GQL
            {
              orders(first: 250, sortKey: CREATED_AT, reverse: true{$qFilter}{$after}) {
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

            $data  = $this->graphql($gql);
            $conn  = $data['data']['orders'] ?? [];
            $edges = $conn['edges'] ?? [];

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

            $hasNext = $conn['pageInfo']['hasNextPage'] ?? false;
            $cursor  = $conn['pageInfo']['endCursor']   ?? null;
            $page++;
        } while ($hasNext && $cursor && $page < $maxPages);

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
            'truncated'    => $hasNext,
            'pages'        => $page,
        ];
    }

    /**
     * Fetches paid orders in a date range with full shipping address fields for address validation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddressScan(string $startDate, string $endDate): array
    {
        $all = [];

        $params = http_build_query([
            'status'             => 'any',
            'financial_status'   => 'paid,partially_paid',
            'fulfillment_status' => 'unfulfilled,partial',
            'created_at_min'   => $startDate . 'T00:00:00-00:00',
            'created_at_max'   => $endDate   . 'T23:59:59-00:00',
            'limit'            => self::PAGE_SIZE,
            'fields'           => 'id,order_number,name,created_at,email,financial_status,fulfillment_status,shipping_address,shipping_lines',
        ]);

        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }

        return $all;
    }

    /**
     * Returns Shopify orders with refunded or partially_refunded financial status
     * in the given date range, including refund line details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchRefundedOrders(string $startDate, string $endDate): array
    {
        $all = [];

        foreach (['refunded', 'partially_refunded'] as $status) {
            $params = http_build_query([
                'status'           => 'any',
                'financial_status' => $status,
                'created_at_min'   => $startDate . 'T00:00:00-00:00',
                'created_at_max'   => $endDate   . 'T23:59:59-00:00',
                'limit'            => self::PAGE_SIZE,
                'fields'           => 'id,order_number,name,financial_status,fulfillment_status,created_at,email,total_price,refunds',
            ]);

            $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
            while ($nextUrl) {
                [$orders, $nextUrl] = $this->getPage($nextUrl);
                array_push($all, ...$orders);
            }
        }

        // Deduplicate (partially_refunded → refunded overlap is unlikely but safe)
        $seen   = [];
        $unique = [];
        foreach ($all as $o) {
            $id = $o['id'] ?? null;
            if ($id && !isset($seen[$id])) {
                $seen[$id] = true;
                $unique[]  = $o;
            }
        }

        usort($unique, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $unique;
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
        $cursor    = null;
        $page      = 0;
        $hasNext   = false;

        do {
            $after = $cursor ? ", after: \"{$cursor}\"" : '';

            $gql = <<<GQL
            {
              orders(first: 250, sortKey: CREATED_AT, reverse: true, query: "email:\"{$safeEmail}\"{$after}") {
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

            $data  = $this->graphql($gql);
            $conn  = $data['data']['orders'] ?? [];
            $edges = $conn['edges'] ?? [];

            foreach ($edges as $e) {
                $node = $e['node'];
                if ($customer === null && isset($node['customer'])) {
                    $customer = $node['customer'];
                }
                $orders[] = $node;
            }

            $hasNext = $conn['pageInfo']['hasNextPage'] ?? false;
            $cursor  = $conn['pageInfo']['endCursor']   ?? null;
            $page++;
        } while ($hasNext && $cursor && $page < $maxPages);

        $totalSpent = 0.0;
        $currency   = 'USD';
        foreach ($orders as $o) {
            $totalSpent += (float) ($o['totalPriceSet']['shopMoney']['amount'] ?? 0);
            $currency    = $o['totalPriceSet']['shopMoney']['currencyCode'] ?? $currency;
        }

        return compact('orders', 'customer', 'totalSpent', 'currency') + ['truncated' => $hasNext];
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
        $cursor   = null;
        $page     = 0;
        $all      = [];
        $hasNext  = false;

        do {
            $after = $cursor ? ", after: \"{$cursor}\"" : '';

            $gql = <<<GQL
            {
              orders(first: 250, sortKey: CREATED_AT, reverse: false, query: "{$queryStr}"{$after}) {
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

            $data  = $this->graphql($gql);
            $conn  = $data['data']['orders'] ?? [];
            foreach ($conn['edges'] ?? [] as $e) $all[] = $e['node'];

            $hasNext = $conn['pageInfo']['hasNextPage'] ?? false;
            $cursor  = $conn['pageInfo']['endCursor']   ?? null;
            $page++;
        } while ($hasNext && $cursor && $page < 40);

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

        return ['pairs' => $pairs, 'scanned' => count($all), 'truncated' => $hasNext];
    }

    // ── Private ───────────────────────────────────────────────────────

    /**
     * Executes a GraphQL query against the Shopify Admin API.
     *
     * @return array<string, mixed>
     */
    private function graphql(string $query): array
    {
        $url     = str_replace('/admin/api/', '/admin/api/', $this->baseUrl) . '/graphql.json';
        $payload = json_encode(['query' => $query]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($err) throw new RuntimeException("Shopify GraphQL cURL error: {$err}");
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Shopify GraphQL error {$code}: {$raw}");
        }

        $decoded = json_decode($raw, true);
        if (isset($decoded['errors'])) {
            throw new RuntimeException("Shopify GraphQL: " . json_encode($decoded['errors']));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Single GET request - returns decoded JSON body as array.
     * Handles rate limiting (HTTP 429) with automatic retry.
     *
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw        = curl_exec($ch);
        $code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err        = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if ($err) throw new RuntimeException("Shopify cURL error: {$err}");

        if ($code === 429) {
            $headers    = substr($raw, 0, $headerSize);
            $retryAfter = 10;
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                $retryAfter = (int) $m[1];
            }
            echo "\n  [Shopify] Rate limited - waiting {$retryAfter}s ...\n";
            sleep($retryAfter);
            return $this->get($url);
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Shopify API error {$code}: " . substr($raw, $headerSize));
        }

        $body    = substr($raw, $headerSize);
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: string|null} */
    private function getPage(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw        = curl_exec($ch);
        $code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err        = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if ($err) throw new RuntimeException("Shopify cURL error: {$err}");

        if ($code === 429) {
            $headers    = substr($raw, 0, $headerSize);
            $retryAfter = 10;
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                $retryAfter = (int) $m[1];
            }
            echo "\n  [Shopify] Rate limited - waiting {$retryAfter}s ...\n";
            sleep($retryAfter);
            return $this->getPage($url);
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Shopify API error {$code}: " . substr($raw, $headerSize));
        }

        $headers = substr($raw, 0, $headerSize);
        $body    = substr($raw, $headerSize);
        $decoded = json_decode($body, true);

        if (!isset($decoded['orders'])) {
            throw new RuntimeException("Shopify unexpected response: {$body}");
        }

        $nextUrl = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/i', $headers, $m)) {
            $nextUrl = $m[1];
        }

        return [$decoded['orders'], $nextUrl];
    }
}
