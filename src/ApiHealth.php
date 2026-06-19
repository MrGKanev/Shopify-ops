<?php
declare(strict_types=1);

/**
 * Lightweight API health and configuration checks.
 */
class ApiHealth
{
    /**
     * @return array<string, mixed>
     */
    public static function checkShopify(string $store, string $token): array
    {
        if (!$token || $store === 'N/A') {
            return ['ok' => false, 'error' => 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set.', 'checks' => []];
        }

        $host = str_contains($store, '.') ? $store : "{$store}.myshopify.com";
        $base = "https://{$host}/admin/api/" . Shopify::API_VERSION;
        $checks = [];

        $shop = self::curlJson("{$base}/shop.json", ["X-Shopify-Access-Token: {$token}", 'Accept: application/json']);
        $checks['shop'] = $shop;

        $scopes = self::curlJson("https://{$host}/admin/oauth/access_scopes.json", ["X-Shopify-Access-Token: {$token}", 'Accept: application/json']);
        $checks['scopes'] = $scopes;

        $scopeNames = [];
        foreach (($scopes['json']['access_scopes'] ?? []) as $scope) {
            $scopeNames[] = $scope['handle'] ?? '';
        }
        $scopeNames = array_values(array_filter($scopeNames));
        $required = ['read_orders', 'read_fulfillments'];
        $missing = ($scopes['ok'] ?? false) ? array_values(array_diff($required, $scopeNames)) : [];

        return [
            'ok'                 => ($shop['ok'] ?? false) && ($scopes['ok'] ?? false) && $missing === [],
            'requested_version'  => Shopify::API_VERSION,
            'returned_version'   => $shop['headers']['x-shopify-api-version'] ?? '',
            'shop_name'          => $shop['json']['shop']['name'] ?? '',
            'scopes'             => $scopeNames,
            'missing_scopes'     => $missing,
            'checks'             => $checks,
            'error'              => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function checkShipStation(string $key, string $secret): array
    {
        if (!$key || !$secret) {
            return ['ok' => false, 'error' => 'SS_API_KEY / SS_API_SECRET not set.', 'checks' => []];
        }

        $auth = base64_encode("{$key}:{$secret}");
        $check = self::curlJson('https://ssapi.shipstation.com/orders?pageSize=1', [
            "Authorization: Basic {$auth}",
            'Accept: application/json',
        ]);

        return [
            'ok'     => $check['ok'] ?? false,
            'error'  => $check['error'] ?? '',
            'checks' => ['orders' => $check],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function curlJson(string $url, array $headers): array
    {
        $ch = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'ShopifyOps/1.0',
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        $t0 = microtime(true);
        $raw = curl_exec($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'ok'      => $code >= 200 && $code < 300,
            'code'    => $code,
            'ms'      => $ms,
            'error'   => $err ?: '',
            'headers' => $responseHeaders,
            'json'    => is_array($json) ? $json : [],
        ];
    }
}
