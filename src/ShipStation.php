<?php
/**
 * ShipStation API client.
 *
 * Fetches orders (paginated) and supports look-ups by order number.
 * API docs: https://www.shipstation.com/docs/api/orders/list-orders/
 */
class ShipStation
{
    private const string BASE_URL  = 'https://ssapi.shipstation.com';
    private const int    PAGE_SIZE = 500;   // max allowed by the API

    private readonly string $auth;
    private readonly ?Cache $cache;

    public function __construct(string $apiKey, string $apiSecret, ?Cache $cache = null)
    {
        $this->auth  = base64_encode("{$apiKey}:{$apiSecret}");
        $this->cache = $cache;
    }

    // ── Public ────────────────────────────────────────────────────────

    /**
     * Returns every order created between $start and $end (inclusive).
     * Results are served from cache when available and fresh.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllOrders(string $startDate, string $endDate): array
    {
        $cpDir   = __DIR__ . '/../cache/checkpoints/ss_' . md5("{$startDate}|{$endDate}");
        $metaFile = $cpDir . '/_meta.json';

        // Cache hit: all pages already on disk and still fresh
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if (is_array($meta) && ($meta['expires_at'] ?? 0) > time()) {
                echo "  [cache] ShipStation orders loaded from checkpoint ({$startDate} → {$endDate})\n";
                return $this->mergePageFiles($cpDir);
            }
        }

        if (!is_dir($cpDir)) {
            mkdir($cpDir, 0755, true);
        }

        // Find last completed page so we can resume after a crash
        $existingPages = glob($cpDir . '/page_*.json') ?: [];
        $startPage     = count($existingPages) + 1;

        if ($startPage > 1) {
            echo "  [checkpoint] Resuming ShipStation fetch from page {$startPage}";
        } else {
            echo "  Fetching ShipStation orders";
        }

        $page       = $startPage;
        $totalPages = null;

        do {
            $params = http_build_query([
                'createDateStart' => $startDate . ' 00:00:00',
                'createDateEnd'   => $endDate   . ' 23:59:59',
                'pageSize'        => self::PAGE_SIZE,
                'page'            => $page,
                'sortBy'          => 'OrderDate',
                'sortDir'         => 'ASC',
            ]);

            $data  = $this->get("/orders?{$params}");
            $batch = $data['orders'] ?? [];

            $totalPages = $data['pages'] ?? $totalPages ?? 1;

            // Write page to disk immediately — survives a crash
            file_put_contents($cpDir . '/page_' . $page . '.json', json_encode($batch), LOCK_EX);
            unset($batch, $data);
            echo '.';
            $page++;
        } while ($page <= $totalPages);

        // Write meta (TTL marker) — page files ARE the cache now
        $ttl = $this->cache ? 82800 : 3600; // 23h or 1h fallback
        file_put_contents($metaFile, json_encode(['expires_at' => time() + $ttl]), LOCK_EX);

        $all = $this->mergePageFiles($cpDir);
        echo " done (" . count($all) . " orders)\n";
        echo "  [cache] ShipStation orders stored ({$startDate} → {$endDate})\n";

        return $all;
    }

    /** Read and merge page files from a checkpoint dir without keeping all in memory at once. */
    private function mergePageFiles(string $cpDir): array
    {
        $files = glob($cpDir . '/page_*.json') ?: [];
        usort($files, fn($a, $b) =>
            (int) sscanf(basename($a), 'page_%d.json')[0] <=>
            (int) sscanf(basename($b), 'page_%d.json')[0]
        );

        $all = [];
        foreach ($files as $f) {
            $batch = json_decode(file_get_contents($f), true) ?? [];
            foreach ($batch as $order) {
                $all[] = $order;
            }
            unset($batch);
        }
        return $all;
    }

    /**
     * Look up orders in ShipStation that match a given Shopify order number.
     * (Not cached — targeted lookup, always live.)
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        $params = http_build_query([
            'orderNumber' => $orderNumber,
            'pageSize'    => 50,
        ]);
        $data = $this->get("/orders?{$params}");
        return $data['orders'] ?? [];
    }

    /**
     * Creates an order in ShipStation from a full Shopify order array.
     * Maps Shopify fields → ShipStation createorder payload.
     *
     * @param  array<string, mixed> $shopifyOrder
     * @return array<string, mixed> Created ShipStation order
     */
    public function createOrder(array $shopifyOrder): array
    {
        return $this->post('/orders/createorder', $this->buildPayload($shopifyOrder));
    }

    /**
     * Builds the ShipStation createorder payload from a Shopify order without sending it.
     * Used by the dry-run preview feature.
     *
     * @param  array<string, mixed> $shopifyOrder
     * @return array<string, mixed>
     */
    public function buildPayload(array $shopifyOrder): array
    {
        $addr = function (array $a): array {
            $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
            return [
                'name'       => $name ?: ($a['name'] ?? ''),
                'company'    => $a['company']  ?? null,
                'street1'    => $a['address1'] ?? null,
                'street2'    => $a['address2'] ?? null,
                'city'       => $a['city']     ?? null,
                'state'      => $a['province_code'] ?? $a['province'] ?? null,
                'postalCode' => $a['zip']      ?? null,
                'country'    => $a['country_code'] ?? $a['country'] ?? null,
                'phone'      => $a['phone']    ?? null,
            ];
        };

        $items = [];
        foreach ($shopifyOrder['line_items'] ?? [] as $li) {
            $items[] = [
                'lineItemKey' => (string) ($li['id'] ?? ''),
                'name'        => $li['title'] ?? '',
                'sku'         => $li['sku']   ?? null,
                'quantity'    => (int)   ($li['quantity'] ?? 1),
                'unitPrice'   => (float) ($li['price']    ?? 0),
            ];
        }

        $shippingAmount = 0.0;
        foreach ($shopifyOrder['shipping_lines'] ?? [] as $sl) {
            $shippingAmount += (float) ($sl['price'] ?? 0);
        }

        return [
            'orderNumber'      => (string) ($shopifyOrder['order_number'] ?? $shopifyOrder['name'] ?? ''),
            'orderDate'        => $shopifyOrder['created_at'] ?? date('c'),
            'orderStatus'      => 'awaiting_shipment',
            'customerEmail'    => $shopifyOrder['email']            ?? null,
            'customerUsername' => $shopifyOrder['email']            ?? null,
            'billTo'           => $addr($shopifyOrder['billing_address']  ?? []),
            'shipTo'           => $addr($shopifyOrder['shipping_address'] ?? $shopifyOrder['billing_address'] ?? []),
            'items'            => $items,
            'amountPaid'       => (float) ($shopifyOrder['total_price']   ?? 0),
            'taxAmount'        => (float) ($shopifyOrder['total_tax']     ?? 0),
            'shippingAmount'   => $shippingAmount,
        ];
    }

    // ── Private ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function post(string $path, array $body): array
    {
        $url = self::BASE_URL . $path;
        $json = json_encode($body);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body_raw = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);

        if ($err) {
            throw new RuntimeException("ShipStation cURL error: {$err}");
        }

        if ($code === 429) {
            sleep(60);
            return $this->post($path, $body);
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("ShipStation API error {$code}: {$body_raw}");
        }

        $decoded = json_decode($body_raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("ShipStation returned non-JSON: {$body_raw}");
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        $url = self::BASE_URL . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($err) {
            throw new RuntimeException("ShipStation cURL error: {$err}");
        }

        // ShipStation rate-limits at 40 req/min — back off on 429
        if ($code === 429) {
            $retryAfter = 60;
            echo "\n  [ShipStation] Rate limited — waiting {$retryAfter}s ...\n";
            sleep($retryAfter);
            return $this->get($path); // one retry
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("ShipStation API error {$code}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("ShipStation returned non-JSON: {$body}");
        }

        return $decoded;
    }
}
