<?php
/**
 * Loads all view data for each page.
 * Returns an array that gets extract()-ed into the view scope.
 */
class PageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        $data = [];

        // Always loaded
        $data += self::loadGlobal($ctx);

        // Page-specific
        $data += match ($page) {
            'run'       => self::loadAudit($action, $ctx, $data),
            'spotcheck' => self::loadSpotCheck($action, $ctx),
            'metafields'=> self::loadMetafields($action, $ctx),
            'tagsearch' => self::loadTagSearch($action, $ctx),
            'tagaudit'  => self::loadTagAudit($action, $ctx),
            'dupes'     => self::loadDuplicates($action, $ctx),
            'settings'  => self::loadSettings($action, $ctx),
            default     => [],
        };

        return $data;
    }

    // ── Always-loaded data ────────────────────────────────────────────────────

    private static function loadGlobal(array $ctx): array
    {
        $reports      = [];
        $orderHistory = [];
        $reportDir    = $ctx['reportDir'];
        $ignoredOrders = $ctx['ignoredOrders'];

        if (is_dir($reportDir)) {
            $files = glob($reportDir . '/missing_*.csv') ?: [];
            rsort($files);

            foreach ($files as $csvPath) {
                preg_match('/missing_(\d{4}-\d{2}-\d{2})\.csv$/', $csvPath, $m);
                $date    = $m[1] ?? 'unknown';
                $rawRows = [];

                if (($fh = fopen($csvPath, 'r')) !== false) {
                    $headers = fgetcsv($fh, 0, ',', '"', '\\');
                    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                        $rawRows[] = array_combine($headers ?: [], $row);
                    }
                    fclose($fh);
                }

                foreach ($rawRows as $row) {
                    $num = Comparator::normalise((string) ($row['order_number'] ?? ''));
                    if (!$num) continue;
                    if (!isset($orderHistory[$num])) {
                        $orderHistory[$num] = ['count' => 0, 'first' => $date, 'last' => $date];
                    }
                    $orderHistory[$num]['count']++;
                    if ($date < $orderHistory[$num]['first']) $orderHistory[$num]['first'] = $date;
                    if ($date > $orderHistory[$num]['last'])  $orderHistory[$num]['last']  = $date;
                }

                $missing = array_values(array_filter(
                    $rawRows,
                    fn($o) => !isset($ignoredOrders[Comparator::normalise((string) ($o['order_number'] ?? ''))])
                ));

                $reports[] = ['date' => $date, 'csvPath' => $csvPath, 'missing' => $missing, 'count' => count($missing)];
            }
        }

        $latestReport = $reports[0] ?? null;
        $selectedDate = $_GET['date'] ?? ($latestReport['date'] ?? null);
        $selectedReport = null;
        foreach ($reports as $r) {
            if ($r['date'] === $selectedDate) { $selectedReport = $r; break; }
        }

        $shopifyStore     = $ctx['shopifyStore'];
        $shopifyAdminBase = 'https://'
            . (str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com")
            . '/admin/orders';

        $pushLog   = PushLog::all();
        $bannedIps = Auth::bannedIps();

        return compact('reports', 'orderHistory', 'latestReport', 'selectedDate', 'selectedReport',
                       'shopifyAdminBase', 'pushLog', 'bannedIps');
    }

    // ── Audit ─────────────────────────────────────────────────────────────────

    private static function loadAudit(string $action, array $ctx, array $already): array
    {
        $auditResult    = null;
        $auditError     = '';
        $auditDuration  = 0;
        $auditFromCache = ['shopify' => false, 'ss' => false];
        $cacheEntries   = $ctx['cacheObj']->entries();
        $cacheFlushed   = 0;
        $auditStart     = $_POST['audit_start'] ?? $_GET['start'] ?? date('Y-m-d', strtotime('-12 months'));
        $auditEnd       = $_POST['audit_end']   ?? $_GET['end']   ?? date('Y-m-d');

        if ($action === 'flush_cache') {
            $cacheFlushed = $ctx['cacheObj']->flush();
            $cacheEntries = $ctx['cacheObj']->entries();
        }

        if ($action === 'run_audit') {
            $auditStart = $_POST['audit_start'] ?? '';
            $auditEnd   = $_POST['audit_end']   ?? '';

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditEnd)) {
                $auditError = 'Invalid date format. Use YYYY-MM-DD.';
            } elseif ($auditStart > $auditEnd) {
                $auditError = 'Start date must be before end date.';
            } elseif (!$ctx['ssKey'] || !$ctx['ssSecret'] || !$ctx['shopifyToken']) {
                $auditError = 'API credentials missing in .env.';
            } else {
                try {
                    if (function_exists('set_time_limit')) set_time_limit(600);
                    ini_set('memory_limit', '512M');
                    $t0 = microtime(true);

                    $ssAuditEnd = date('Y-m-d', strtotime($auditEnd . ' +7 days'));

                    $auditFromCache = [
                        'shopify' => $ctx['cacheObj']->isFresh('shopify', "{$auditStart}|{$auditEnd}"),
                        'ss'      => $ctx['cacheObj']->isFresh('ss',      "{$auditStart}|{$ssAuditEnd}"),
                    ];

                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);

                    $shopifyOrders = $shopify->fetchAllOrders($auditStart, $auditEnd);
                    $ssOrders      = $ss->fetchAllOrders($auditStart, $ssAuditEnd);

                    $ssIndex      = Comparator::buildSSIndex($ssOrders);
                    $ssEmailIndex = Comparator::buildSSEmailIndex($ssOrders);
                    $comparison   = Comparator::compare($shopifyOrders, $ssIndex, $ctx['ignoredOrders'], $ssEmailIndex);

                    Reporter::saveReports($comparison['missing'], $auditStart, $auditEnd);

                    $auditDuration = round(microtime(true) - $t0, 1);
                    $auditResult   = [
                        'missing'    => $comparison['missing'],
                        'ignored'    => $comparison['ignored'],
                        'found'      => count($comparison['found']),
                        'skipped'    => count($comparison['skipped']),
                        'total_ss'   => count($ssOrders),
                        'duplicates' => Comparator::findDuplicates($shopifyOrders),
                    ];

                    $cacheEntries = $ctx['cacheObj']->entries();
                } catch (Throwable $e) {
                    $auditError = $e->getMessage();
                }
            }
        }

        $cacheTtl = $ctx['cacheTtl'];
        return compact('auditResult', 'auditError', 'auditDuration', 'auditFromCache',
                       'auditStart', 'auditEnd', 'cacheEntries', 'cacheFlushed', 'cacheTtl');
    }

    // ── Spot-check ────────────────────────────────────────────────────────────

    private static function loadSpotCheck(string $action, array $ctx): array
    {
        $spotResults = null;
        $spotError   = '';
        $spotInput   = trim($_GET['prefill'] ?? '');

        if ($action === 'spotcheck') {
            $spotInput = trim($_POST['orders'] ?? '');
            $numbers   = array_filter(array_map('trim', preg_split('/[\s,]+/', $spotInput)));

            if (empty($numbers)) {
                $spotError = 'Enter at least one order number.';
            } elseif (count($numbers) > 50) {
                $spotError = 'Maximum 50 order numbers at once.';
            } else {
                $spotMode = $_POST['spotcheck_mode'] ?? 'both';
                $checkSS  = in_array($spotMode, ['both', 'ss'], true);
                $checkSh  = in_array($spotMode, ['both', 'shopify'], true)
                    && $ctx['shopifyToken'] && $ctx['shopifyStore'] !== 'N/A';

                if ($checkSS && (!$ctx['ssKey'] || !$ctx['ssSecret'])) {
                    $spotError = 'SS_API_KEY / SS_API_SECRET not set in .env.';
                } else {
                    try {
                        $ss      = $checkSS ? new ShipStation($ctx['ssKey'], $ctx['ssSecret']) : null;
                        $shopify = $checkSh ? new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']) : null;

                        $spotResults = [];
                        foreach ($numbers as $num) {
                            $clean    = ltrim(trim($num), '#');
                            $ssOrders = $ss      ? $ss->findByOrderNumber($clean)     : null;
                            $shOrders = $shopify ? $shopify->findByOrderNumber($clean) : null;

                            $spotResults[] = [
                                'input'          => $num,
                                'number'         => $clean,
                                'mode'           => $spotMode,
                                'ss_orders'      => $ssOrders,
                                'ss_found'       => !empty($ssOrders),
                                'shopify_orders' => $shOrders,
                                'shopify_found'  => !empty($shOrders),
                                'orders'         => $ssOrders ?? [],
                                'found'          => !empty($ssOrders),
                            ];
                        }
                    } catch (Throwable $e) {
                        $spotError = 'Error: ' . $e->getMessage();
                    }
                }
            }
        }

        return compact('spotResults', 'spotInput', 'spotError');
    }

    // ── Metafields ────────────────────────────────────────────────────────────

    private static function loadMetafields(string $action, array $ctx): array
    {
        $metafieldDefs        = null;
        $metafieldOrders      = null;
        $metafieldInput       = '';
        $metafieldError       = '';
        $metafieldFilter      = '';
        $metafieldSearch      = null;
        $metafieldSearchError = '';

        if (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
            $metafieldError = 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.';
            return compact('metafieldDefs', 'metafieldOrders', 'metafieldInput', 'metafieldError',
                           'metafieldFilter', 'metafieldSearch', 'metafieldSearchError');
        }

        $shopifyMeta = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);

        try {
            $metafieldDefs = $shopifyMeta->fetchMetafieldDefinitions('ORDER');
        } catch (Throwable $e) {
            $metafieldError = 'Could not load metafield definitions: ' . $e->getMessage();
        }

        if ($action === 'metafield_search') {
            $mfNs    = trim($_POST['mf_ns']    ?? '');
            $mfKey   = trim($_POST['mf_key']   ?? '');
            $mfVal   = trim($_POST['mf_value'] ?? '');
            $mfStart = trim($_POST['mf_start'] ?? '');
            $mfEnd   = trim($_POST['mf_end']   ?? '');

            if (!$mfNs || !$mfKey) {
                $metafieldSearchError = 'Namespace and key are required.';
            } else {
                try {
                    if (function_exists('set_time_limit')) set_time_limit(120);
                    $result = $shopifyMeta->searchOrdersByMetafield($mfNs, $mfKey, $mfVal, $mfStart, $mfEnd);
                    $metafieldSearch = [
                        'namespace'     => $mfNs,
                        'key'           => $mfKey,
                        'value'         => $mfVal,
                        'start'         => $mfStart,
                        'end'           => $mfEnd,
                        'orders'        => $result['matches'],
                        'scanned'       => $result['scanned'],
                        'with_mf'       => $result['with_mf'],
                        'sample_values' => $result['sample_values'],
                        'pages'         => $result['pages'],
                        'truncated'     => $result['truncated'],
                    ];
                } catch (Throwable $e) {
                    $metafieldSearchError = $e->getMessage();
                }
            }
        }

        if ($action === 'metafield_lookup') {
            $metafieldInput  = trim($_POST['mf_orders'] ?? '');
            $metafieldFilter = trim($_POST['mf_filter'] ?? '');
            $numbers         = array_filter(array_map('trim', preg_split('/[\s,]+/', $metafieldInput)));

            if (empty($numbers)) {
                $metafieldError = 'Enter at least one order number.';
            } elseif (count($numbers) > 20) {
                $metafieldError = 'Maximum 20 order numbers at once.';
            } else {
                $metafieldOrders = [];
                foreach ($numbers as $num) {
                    $clean    = ltrim($num, '#');
                    $shOrders = $shopifyMeta->findByOrderNumber($clean);

                    if (empty($shOrders)) {
                        $metafieldOrders[] = ['number' => $clean, 'shopify_id' => null, 'metafields' => [], 'found' => false];
                        continue;
                    }

                    foreach ($shOrders as $shOrder) {
                        $oid = (string) ($shOrder['id'] ?? '');
                        $mfs = $oid ? $shopifyMeta->getOrderMetafields($oid) : [];

                        if ($metafieldFilter !== '') {
                            $mfs = array_values(array_filter($mfs, function ($mf) use ($metafieldFilter) {
                                $nk = ($mf['namespace'] ?? '') . '.' . ($mf['key'] ?? '');
                                return stripos($nk, $metafieldFilter) !== false
                                    || stripos((string) ($mf['value'] ?? ''), $metafieldFilter) !== false;
                            }));
                        }

                        $metafieldOrders[] = [
                            'number'     => $clean,
                            'shopify_id' => $oid,
                            'name'       => $shOrder['name'] ?? ('#' . $clean),
                            'metafields' => $mfs,
                            'found'      => true,
                        ];
                    }
                }
            }
        }

        return compact('metafieldDefs', 'metafieldOrders', 'metafieldInput', 'metafieldError',
                       'metafieldFilter', 'metafieldSearch', 'metafieldSearchError');
    }

    // ── Tag search ────────────────────────────────────────────────────────────

    private static function loadTagSearch(string $action, array $ctx): array
    {
        $tagSearch      = null;
        $tagSearchError = '';
        $tagInput       = '';
        $tagStart       = '';
        $tagEnd         = '';

        if ($action === 'tag_search') {
            $tagInput = trim($_POST['tag_input'] ?? '');
            $tagStart = trim($_POST['tag_start'] ?? '');
            $tagEnd   = trim($_POST['tag_end']   ?? '');

            if (!$tagInput) {
                $tagSearchError = 'Enter at least one tag.';
            } elseif (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
                $tagSearchError = 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.';
            } else {
                try {
                    if (function_exists('set_time_limit')) set_time_limit(120);
                    $shopifyTag = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $tagResult  = $shopifyTag->searchOrdersByTag($tagInput, $tagStart, $tagEnd);
                    $tagSearch  = array_merge($tagResult, [
                        'tag'   => $tagInput,
                        'start' => $tagStart,
                        'end'   => $tagEnd,
                    ]);
                } catch (Throwable $e) {
                    $tagSearchError = $e->getMessage();
                }
            }
        }

        return compact('tagSearch', 'tagSearchError', 'tagInput', 'tagStart', 'tagEnd');
    }

    // ── Tag Audit ─────────────────────────────────────────────────────────────

    private static function loadTagAudit(string $action, array $ctx): array
    {
        $tagAuditResult = null;
        $tagAuditError  = '';
        $taStart        = $_POST['ta_start'] ?? $_GET['ta_start'] ?? date('Y-m-d', strtotime('-90 days'));
        $taEnd          = $_POST['ta_end']   ?? $_GET['ta_end']   ?? date('Y-m-d');

        if ($action === 'tag_audit') {
            $taStart = trim($_POST['ta_start'] ?? '');
            $taEnd   = trim($_POST['ta_end']   ?? '');

            if (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
                $tagAuditError = 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $taStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $taEnd)) {
                $tagAuditError = 'Invalid date format. Use YYYY-MM-DD.';
            } else {
                try {
                    if (function_exists('set_time_limit')) set_time_limit(300);
                    $shopify        = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $tagAuditResult = $shopify->fetchTagStats($taStart, $taEnd);
                    $tagAuditResult['start'] = $taStart;
                    $tagAuditResult['end']   = $taEnd;
                } catch (Throwable $e) {
                    $tagAuditError = $e->getMessage();
                }
            }
        }

        return compact('tagAuditResult', 'tagAuditError', 'taStart', 'taEnd');
    }

    // ── Duplicate Detector ────────────────────────────────────────────────────

    private static function loadDuplicates(string $action, array $ctx): array
    {
        $dupesResult = null;
        $dupesError  = '';
        $dupesStart  = $_POST['dupes_start'] ?? $_GET['dupes_start'] ?? date('Y-m-d', strtotime('-30 days'));
        $dupesEnd    = $_POST['dupes_end']   ?? $_GET['dupes_end']   ?? date('Y-m-d');

        if ($action === 'find_dupes') {
            $dupesStart = trim($_POST['dupes_start'] ?? '');
            $dupesEnd   = trim($_POST['dupes_end']   ?? '');

            if (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
                $dupesError = 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dupesStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dupesEnd)) {
                $dupesError = 'Invalid date format. Use YYYY-MM-DD.';
            } elseif ($dupesStart > $dupesEnd) {
                $dupesError = 'Start date must be before end date.';
            } else {
                try {
                    if (function_exists('set_time_limit')) set_time_limit(300);
                    $shopify     = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $dupesResult = $shopify->findDuplicateOrders($dupesStart, $dupesEnd);
                    $dupesResult['start'] = $dupesStart;
                    $dupesResult['end']   = $dupesEnd;
                } catch (Throwable $e) {
                    $dupesError = $e->getMessage();
                }
            }
        }

        return compact('dupesResult', 'dupesError', 'dupesStart', 'dupesEnd');
    }

    // ── Settings ──────────────────────────────────────────────────────────────

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
            $ping = function (string $url, array $headers): array {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_USERAGENT      => 'ShopifyOps/1.0',
                ]);
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
                    "https://{$host}/admin/api/2024-01/shop.json",
                    ["X-Shopify-Access-Token: {$ctx['shopifyToken']}", 'Accept: application/json']
                );
            } else {
                $connResults['shopify'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env'];
            }
        }

        return compact('connResults', 'cacheEntries', 'cacheFlushed', 'cacheTtl');
    }
}
