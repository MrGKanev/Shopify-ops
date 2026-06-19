<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;

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
    public const string API_VERSION = '2026-04';

    private readonly string $baseUrl;
    private readonly string $token;
    private readonly ?Cache $cache;
    private readonly Client $http;

    public function __construct(
        string $store,
        string $accessToken,
        ?Cache $cache = null,
        ?HandlerStack $stack = null
    ) {
        $host = str_contains($store, '.') ? $store : "{$store}.myshopify.com";
        $this->baseUrl = "https://{$host}/admin/api/" . self::API_VERSION;
        $this->token   = $accessToken;
        $this->cache   = $cache;
        $stack ??= HandlerStack::create();
        $stack->push(Middleware::retry(
            function (int $retries, $req, ?ResponseInterface $res = null) {
                if ($res?->getStatusCode() !== 429 || $retries >= 5) return false;
                $h    = $res->getHeaderLine('Retry-After');
                $wait = $h !== '' ? (int)$h : 10;
                echo "\n  [Shopify] Rate limited - waiting {$wait}s ...\n";
                return true;
            },
            function ($retries, $res) {
                $h = $res?->getHeaderLine('Retry-After') ?? '';
                return ($h !== '' ? (int)$h : 10) * 1000;
            }
        ));
        $this->http = new Client(['handler' => $stack]);
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
        $all = [];

        $query = [
            'status'           => 'any',
            'financial_status' => 'paid,partially_paid',
            'created_at_min'   => $startDate . 'T00:00:00-00:00',
            'created_at_max'   => $endDate   . 'T23:59:59-00:00',
            'limit'            => self::PAGE_SIZE,
            'fields'           => 'id,order_number,name,created_at,email,financial_status,fulfillment_status,shipping_address,shipping_lines',
        ];
        if ($unfulfilledOnly) {
            $query['fulfillment_status'] = 'unfulfilled,partial';
        }

        $nextUrl = "{$this->baseUrl}/orders.json?" . http_build_query($query);
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
    public function fetchOrdersForHighValue(string $startDate, string $endDate): array
    {
        $all = [];
        $params = http_build_query([
            'status'             => 'any',
            'financial_status'   => 'paid,partially_paid',
            'fulfillment_status' => 'unfulfilled,partial',
            'created_at_min'     => $startDate . 'T00:00:00-00:00',
            'created_at_max'     => $endDate   . 'T23:59:59-00:00',
            'limit'              => self::PAGE_SIZE,
            'fields'             => 'id,order_number,name,created_at,email,total_price,shipping_address,shipping_lines',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    /**
     * Fetches orders whose shipping address was changed after the order was placed.
     * Strategy: paginate /events.json for Order events in the window, filter for
     * address-change messages, then fetch the matching orders by ID in one batch call.
     *
     * @return array<int, array<string, mixed>>  each entry has 'order' + 'changed_at'
     */
    public function fetchOrdersWithAddressChanges(string $startDate, string $endDate): array
    {
        // 1. Walk all Order events in the window
        $params  = http_build_query([
            'subject_type'   => 'Order',
            'created_at_min' => $startDate . 'T00:00:00-00:00',
            'created_at_max' => $endDate   . 'T23:59:59-00:00',
            'limit'          => self::PAGE_SIZE,
        ]);
        $nextUrl = "{$this->baseUrl}/events.json?{$params}";

        // subject_id => most-recent change timestamp
        $changed = [];
        while ($nextUrl) {
            [$page, $nextUrl] = $this->getPage($nextUrl, 'events');
            foreach ($page as $ev) {
                $msg = strtolower($ev['message'] ?? '');
                // Shopify logs address edits as: "Shipping address was updated to …"
                if (str_contains($msg, 'shipping address') || str_contains($msg, 'address was')) {
                    $id = (string)($ev['subject_id'] ?? '');
                    if (!$id) continue;
                    // keep the latest event timestamp per order
                    $ts = $ev['created_at'] ?? '';
                    if (!isset($changed[$id]) || $ts > $changed[$id]) {
                        $changed[$id] = $ts;
                    }
                }
            }
        }

        if (empty($changed)) return [];

        // 2. Fetch the matching orders in batches of 250
        $ids    = array_keys($changed);
        $orders = [];
        foreach (array_chunk($ids, 250) as $chunk) {
            $p = http_build_query([
                'ids'    => implode(',', $chunk),
                'status' => 'any',
                'limit'  => 250,
                'fields' => 'id,order_number,name,created_at,email,total_price,financial_status,fulfillment_status,shipping_address',
            ]);
            $data = $this->get("{$this->baseUrl}/orders.json?{$p}");
            foreach ($data['orders'] ?? [] as $o) {
                $orders[] = [
                    'order'      => $o,
                    'changed_at' => $changed[(string)$o['id']] ?? '',
                ];
            }
        }

        usort($orders, fn($a, $b) => strcmp($b['changed_at'], $a['changed_at']));

        return $orders;
    }

    /**
     * Finds orders that had content edits (line items, notes, custom attributes, discounts)
     * after placement, using the Events API. Returns orders sorted by edit date desc.
     *
     * Each entry: shopify_id, order_number, created_at, edited_at, diff_mins,
     *             email, total, financial, fulfillment, edit_summary (string[])
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchEditedOrders(string $startDate, string $endDate): array
    {
        $params  = http_build_query([
            'subject_type'   => 'Order',
            'created_at_min' => $startDate . 'T00:00:00-00:00',
            'created_at_max' => $endDate   . 'T23:59:59-00:00',
            'limit'          => self::PAGE_SIZE,
        ]);
        $nextUrl = "{$this->baseUrl}/events.json?{$params}";

        $byOrder = [];
        while ($nextUrl) {
            [$page, $nextUrl] = $this->getPage($nextUrl, 'events');
            foreach ($page as $ev) {
                $verb = strtolower($ev['verb']    ?? '');
                $msg  = strtolower($ev['message'] ?? '');
                $isEdit = $verb === 'edit_complete'
                    || str_contains($msg, 'was edited')
                    || str_contains($msg, 'were edited')
                    || str_contains($msg, 'item was added')
                    || str_contains($msg, 'item was removed')
                    || str_contains($msg, 'discount was added')
                    || str_contains($msg, 'discount was removed')
                    || str_contains($msg, 'note was updated')
                    || str_contains($msg, 'custom attributes');
                if (!$isEdit) continue;

                $id = (string)($ev['subject_id'] ?? '');
                if (!$id) continue;
                $ts = $ev['created_at'] ?? '';
                if (!isset($byOrder[$id])) {
                    $byOrder[$id] = ['latest_at' => $ts, 'summary' => []];
                } elseif ($ts > $byOrder[$id]['latest_at']) {
                    $byOrder[$id]['latest_at'] = $ts;
                }
                $short = ucfirst($ev['message'] ?? '');
                if (count($byOrder[$id]['summary']) < 4 && !in_array($short, $byOrder[$id]['summary'], true)) {
                    $byOrder[$id]['summary'][] = $short;
                }
            }
        }

        if (empty($byOrder)) return [];

        $rows = [];
        foreach (array_chunk(array_keys($byOrder), 250) as $chunk) {
            $p = http_build_query([
                'ids'    => implode(',', $chunk),
                'status' => 'any',
                'limit'  => 250,
                'fields' => 'id,order_number,name,created_at,updated_at,email,total_price,financial_status,fulfillment_status',
            ]);
            $data = $this->get("{$this->baseUrl}/orders.json?{$p}");
            foreach ($data['orders'] ?? [] as $o) {
                $oid       = (string)$o['id'];
                $ev        = $byOrder[$oid] ?? [];
                $createdTs = strtotime($o['created_at'] ?? '');
                $editedTs  = strtotime($ev['latest_at']  ?? '');
                $diffMins  = ($createdTs && $editedTs) ? max(0, (int)(($editedTs - $createdTs) / 60)) : 0;
                $rows[] = [
                    'shopify_id'   => $oid,
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
        }

        usort($rows, fn($a, $b) => strcmp($b['edited_at'], $a['edited_at']));
        return $rows;
    }

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
        $params = http_build_query(['limit' => 250]);
        $data   = $this->get("{$this->baseUrl}/orders/{$orderId}/events.json?{$params}");
        return $data['events'] ?? [];
    }

    /**
     * Paid orders where billing country != shipping country.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForCountryMismatch(string $startDate, string $endDate): array
    {
        $all = [];
        $params = http_build_query([
            'status'           => 'any',
            'financial_status' => 'paid,partially_paid',
            'created_at_min'   => $startDate . 'T00:00:00-00:00',
            'created_at_max'   => $endDate   . 'T23:59:59-00:00',
            'limit'            => self::PAGE_SIZE,
            'fields'           => 'id,order_number,name,created_at,email,financial_status,fulfillment_status,total_price,billing_address,shipping_address',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    /**
     * Open orders in 'partial' fulfillment status - includes line_items + fulfillments
     * so callers can determine which items remain unfulfilled and for how long.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchPartiallyFulfilledOrders(string $startDate, string $endDate): array
    {
        $all = [];
        $params = http_build_query([
            'status'             => 'open',
            'fulfillment_status' => 'partial',
            'created_at_min'     => $startDate . 'T00:00:00-00:00',
            'created_at_max'     => $endDate   . 'T23:59:59-00:00',
            'limit'              => self::PAGE_SIZE,
            'fields'             => 'id,order_number,name,created_at,email,financial_status,fulfillment_status,total_price,line_items,fulfillments',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    /**
     * Fetches all products from the store. $status can be 'active', 'draft', 'archived', or 'any'.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllProducts(string $status = 'active'): array
    {
        $all = [];
        $params = http_build_query([
            'status' => $status,
            'limit'  => self::PAGE_SIZE,
            'fields' => 'id,title,status,body_html,vendor,product_type,images,variants',
        ]);
        $nextUrl = "{$this->baseUrl}/products.json?{$params}";
        while ($nextUrl) {
            [$products, $nextUrl] = $this->getPage($nextUrl, 'products');
            array_push($all, ...$products);
        }
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
        $all = [];
        $params = http_build_query([
            'status'             => 'any',
            'fulfillment_status' => 'fulfilled,partial',
            'created_at_min'     => $startDate . 'T00:00:00-00:00',
            'created_at_max'     => $endDate   . 'T23:59:59-00:00',
            'limit'              => self::PAGE_SIZE,
            'fields'             => 'id,order_number,name,created_at,email,financial_status,fulfillment_status,total_price,fulfillments',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    /**
     * Returns orders where the shipping address was changed AFTER the first fulfillment was created.
     * Builds on the same events-API strategy as fetchOrdersWithAddressChanges but includes
     * fulfillments in the batch order fetch to compare timestamps.
     *
     * @return array<int, array{order: array, changed_at: string, fulfillment_at: string}>
     */
    public function fetchPostShipAddressChanges(string $startDate, string $endDate): array
    {
        $params  = http_build_query([
            'subject_type'   => 'Order',
            'created_at_min' => $startDate . 'T00:00:00-00:00',
            'created_at_max' => $endDate   . 'T23:59:59-00:00',
            'limit'          => self::PAGE_SIZE,
        ]);
        $nextUrl = "{$this->baseUrl}/events.json?{$params}";

        $changed = [];
        while ($nextUrl) {
            [$page, $nextUrl] = $this->getPage($nextUrl, 'events');
            foreach ($page as $ev) {
                $msg = strtolower($ev['message'] ?? '');
                if (str_contains($msg, 'shipping address') || str_contains($msg, 'address was')) {
                    $id = (string)($ev['subject_id'] ?? '');
                    if (!$id) continue;
                    $ts = $ev['created_at'] ?? '';
                    if (!isset($changed[$id]) || $ts > $changed[$id]) {
                        $changed[$id] = $ts;
                    }
                }
            }
        }

        if (empty($changed)) return [];

        $orders = [];
        foreach (array_chunk(array_keys($changed), 250) as $chunk) {
            $p = http_build_query([
                'ids'    => implode(',', $chunk),
                'status' => 'any',
                'limit'  => 250,
                'fields' => 'id,order_number,name,created_at,email,total_price,financial_status,fulfillment_status,shipping_address,fulfillments',
            ]);
            $data = $this->get("{$this->baseUrl}/orders.json?{$p}");
            foreach ($data['orders'] ?? [] as $o) {
                $oid       = (string)$o['id'];
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
        $all = [];
        $params = http_build_query([
            'status'             => 'any',
            'financial_status'   => 'paid,partially_paid',
            'fulfillment_status' => 'unfulfilled,partial',
            'created_at_min'     => $startDate . 'T00:00:00-00:00',
            'created_at_max'     => $endDate   . 'T23:59:59-00:00',
            'limit'              => self::PAGE_SIZE,
            'fields'             => 'id,order_number,name,created_at,email,financial_status,fulfillment_status,total_price,note',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    /**
     * Fetches paid orders with shipping address data for duplicate-address analysis.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddrDupes(string $startDate, string $endDate): array
    {
        $all = [];
        $params = http_build_query([
            'status'           => 'any',
            'financial_status' => 'paid,partially_paid',
            'created_at_min'   => $startDate . 'T00:00:00-00:00',
            'created_at_max'   => $endDate   . 'T23:59:59-00:00',
            'limit'            => self::PAGE_SIZE,
            'fields'           => 'id,order_number,name,created_at,email,total_price,shipping_address,fulfillment_status',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    /**
     * Fetches paid orders with shipping method, destination, and fulfillment data for SLA checks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForSla(string $startDate, string $endDate): array
    {
        $all = [];
        $params = http_build_query([
            'status'           => 'any',
            'financial_status' => 'paid,partially_paid',
            'created_at_min'   => $startDate . 'T00:00:00-00:00',
            'created_at_max'   => $endDate   . 'T23:59:59-00:00',
            'limit'            => self::PAGE_SIZE,
            'fields'           => 'id,order_number,name,created_at,email,total_price,financial_status,fulfillment_status,shipping_lines,shipping_address,fulfillments,line_items',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    /**
     * Fetches cancelled Shopify orders in a date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCancelledOrders(string $startDate, string $endDate): array
    {
        $all = [];
        $params = http_build_query([
            'status'           => 'cancelled',
            'created_at_min'   => $startDate . 'T00:00:00-00:00',
            'created_at_max'   => $endDate   . 'T23:59:59-00:00',
            'limit'            => self::PAGE_SIZE,
            'fields'           => 'id,order_number,name,created_at,cancelled_at,cancel_reason,email,total_price,financial_status,fulfillment_status',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    /**
     * Fetches paid orders with discount and shipping address fields for abuse clustering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForDiscountAudit(string $startDate, string $endDate): array
    {
        $all = [];
        $params = http_build_query([
            'status'           => 'any',
            'financial_status' => 'paid,partially_paid',
            'created_at_min'   => $startDate . 'T00:00:00-00:00',
            'created_at_max'   => $endDate   . 'T23:59:59-00:00',
            'limit'            => self::PAGE_SIZE,
            'fields'           => 'id,order_number,name,created_at,email,total_price,financial_status,fulfillment_status,discount_codes,shipping_address',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    /**
     * Fetches paid orders with tags for policy validation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForTagPolicy(string $startDate, string $endDate): array
    {
        $all = [];
        $params = http_build_query([
            'status'           => 'any',
            'financial_status' => 'paid,partially_paid',
            'created_at_min'   => $startDate . 'T00:00:00-00:00',
            'created_at_max'   => $endDate   . 'T23:59:59-00:00',
            'limit'            => self::PAGE_SIZE,
            'fields'           => 'id,order_number,name,created_at,email,total_price,financial_status,fulfillment_status,tags',
        ]);
        $nextUrl = "{$this->baseUrl}/orders.json?{$params}";
        while ($nextUrl) {
            [$orders, $nextUrl] = $this->getPage($nextUrl);
            array_push($all, ...$orders);
        }
        return $all;
    }

    // ── Private ───────────────────────────────────────────────────────

    /**
     * Sends a request with auth headers and handles 429 retry automatically.
     * Pass 'json' in $options for POST bodies (Guzzle encodes + sets Content-Type).
     */
    private function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['http_errors']                        = false;
        $options['timeout']                            = $options['timeout'] ?? 30;
        $options['headers']['X-Shopify-Access-Token']  = $this->token;
        $options['headers']['Content-Type']           ??= 'application/json';

        return $this->http->request($method, $url, $options);
    }

    /**
     * Executes a GraphQL query against the Shopify Admin API.
     *
     * @return array<string, mixed>
     */
    private function graphql(string $query): array
    {
        $response = $this->request('POST', $this->baseUrl . '/graphql.json', [
            'json' => ['query' => $query],
        ]);

        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Shopify GraphQL error {$code}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (isset($decoded['errors'])) {
            throw new RuntimeException("Shopify GraphQL: " . json_encode($decoded['errors']));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Single GET request - returns decoded JSON body as array.
     *
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $response = $this->request('GET', $url);
        $code     = $response->getStatusCode();
        $body     = (string) $response->getBody();

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Shopify API error {$code}: {$body}");
        }

        return json_decode($body, true) ?? [];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: string|null} */
    private function getPage(string $url, string $rootKey = 'orders'): array
    {
        $response = $this->request('GET', $url);
        $code     = $response->getStatusCode();
        $body     = (string) $response->getBody();

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Shopify API error {$code}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (!isset($decoded[$rootKey])) {
            throw new RuntimeException("Shopify unexpected response (key={$rootKey}): {$body}");
        }

        $nextUrl = null;
        $link    = $response->getHeaderLine('Link');
        if ($link && preg_match('/<([^>]+)>;\s*rel="next"/i', $link, $m)) {
            $nextUrl = $m[1];
        }

        return [$decoded[$rootKey], $nextUrl];
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
        $cursor  = null;
        $page    = 0;
        $hasNext = false;

        do {
            $after = $cursor ? ", after: \"{$cursor}\"" : '';
            $gql   = str_replace('{{AFTER}}', $after, $queryTemplate);

            $data    = $this->graphql($gql);
            $conn    = $data['data'][$rootKey] ?? [];
            $edges   = $conn['edges'] ?? [];

            $processor($edges);

            $hasNext = $conn['pageInfo']['hasNextPage'] ?? false;
            $cursor  = $conn['pageInfo']['endCursor']   ?? null;
            $page++;
        } while ($hasNext && $cursor && $page < $maxPages);

        return ['truncated' => $hasNext, 'pages' => $page];
    }
}
