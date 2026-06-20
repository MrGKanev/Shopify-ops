<?php
declare(strict_types=1);

/**
 * Loads low-risk manage/settings pages that do not share audit/search helpers.
 */
class ManageSettingsPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'jobs'        => self::loadJobs(),
            'slackrules'  => self::loadSlackRules(),
            'apihealth'   => self::loadApiHealth($action, $ctx),
            'configcheck' => self::loadConfigCheck(),
            'actionlog'   => self::loadActionLog(),
            'settings'    => self::loadSettings($action, $ctx),
            default       => [],
        };
    }

    private static function loadJobs(): array
    {
        $jobs = JobQueue::all();
        return compact('jobs');
    }

    private static function loadSlackRules(): array
    {
        $slackRules = SlackRules::load();
        $slackConfigured = SlackNotifier::isConfigured();
        return compact('slackRules', 'slackConfigured');
    }

    private static function loadApiHealth(string $action, array $ctx): array
    {
        $apiHealth = null;
        if ($action === 'refresh_api_health') {
            $apiHealth = [
                'shopify'     => ApiHealth::checkShopify($ctx['shopifyStore'], $ctx['shopifyToken']),
                'shipstation' => ApiHealth::checkShipStation($ctx['ssKey'], $ctx['ssSecret']),
                'checked_at'   => date('Y-m-d H:i:s'),
            ];
            RunLog::append([
                'tool'       => 'api_health',
                'status'     => (($apiHealth['shopify']['ok'] ?? false) && ($apiHealth['shipstation']['ok'] ?? false)) ? 'ok' : 'issues_found',
                'rows_found' => count($apiHealth['shopify']['missing_scopes'] ?? []),
                'meta'       => ['api_version' => Shopify::API_VERSION],
            ]);
        }
        return compact('apiHealth');
    }

    private static function loadConfigCheck(): array
    {
        $configResults = ConfigValidator::validateAll(dirname(__DIR__));
        return compact('configResults');
    }

    private static function loadActionLog(): array
    {
        $actionLog = UserActionLog::all();
        return compact('actionLog');
    }

    private static function loadSettings(string $action, array $ctx): array
    {
        $connResults  = null;
        $cacheEntries = $ctx['cacheObj']->entries();
        $cacheFlushed = 0;
        $cacheTtl     = $ctx['cacheTtl'];

        if ($action === 'flush_cache') {
            $cacheFlushed = $ctx['cacheObj']->flush();
            $cacheEntries = $ctx['cacheObj']->entries();
        }

        if ($action === 'test_connection') {
            $ping = function (string $url, array $headers, string $method = 'GET', ?string $body = null): array {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_USERAGENT      => 'ShopifyOps/1.0',
                ]);
                if ($method !== 'GET') {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                }
                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                $t0   = microtime(true);
                curl_exec($ch);
                $ms   = (int) round((microtime(true) - $t0) * 1000);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'ms' => $ms, 'error' => $err ?: null];
            };

            if ($ctx['ssKey'] && $ctx['ssSecret']) {
                $auth = base64_encode("{$ctx['ssKey']}:{$ctx['ssSecret']}");
                $connResults['ss'] = $ping(
                    'https://ssapi.shipstation.com/orders?pageSize=1',
                    ["Authorization: Basic {$auth}", 'Accept: application/json']
                );
            } else {
                $connResults['ss'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SS_API_KEY / SS_API_SECRET not set in .env'];
            }

            if ($ctx['shopifyToken'] && $ctx['shopifyStore'] !== 'N/A') {
                $host = str_contains($ctx['shopifyStore'], '.') ? $ctx['shopifyStore'] : "{$ctx['shopifyStore']}.myshopify.com";
                $connResults['shopify'] = $ping(
                    "https://{$host}/admin/api/" . Shopify::API_VERSION . "/graphql.json",
                    [
                        "X-Shopify-Access-Token: {$ctx['shopifyToken']}",
                        'Accept: application/json',
                        'Content-Type: application/json',
                    ],
                    'POST',
                    json_encode(['query' => '{ shop { name } }'])
                );
            } else {
                $connResults['shopify'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env'];
            }
        }

        return compact('connResults', 'cacheEntries', 'cacheFlushed', 'cacheTtl');
    }
}
