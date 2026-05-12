<?php
declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/src/Env.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/IgnoreList.php';
require_once __DIR__ . '/src/PushLog.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/ShipStation.php';
require_once __DIR__ . '/src/Shopify.php';
require_once __DIR__ . '/src/Comparator.php';
require_once __DIR__ . '/src/Reporter.php';
require_once __DIR__ . '/src/ViewHelpers.php';

Env::load(__DIR__ . '/.env');

$webUsername  = getenv('WEB_USERNAME') ?: 'admin';
$webPassword  = getenv('WEB_PASSWORD') ?: 'changeme';
$shopifyStore = getenv('SHOPIFY_STORE') ?: 'N/A';
$appTitle     = getenv('APP_TITLE') ?: 'ShipStation ↔ Shopify Audit';
$appBrand     = getenv('APP_BRAND') ?: 'ShipStation ↔ Shopify';
$appLogo      = getenv('APP_LOGO') ?: '';
$reportDir    = __DIR__ . '/reports';

// ── Session / auth ────────────────────────────────────────────────────────────

session_start();

$error  = '';
$action = $_POST['action'] ?? '';

if ($action === 'login') {
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $error = Auth::attempt($_POST['username'] ?? '', $_POST['password'] ?? '', $webUsername, $webPassword, $ip);
    if ($error === '') {
        $_SESSION['authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

if ($action === 'logout') {
    Auth::logout();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$authed = !empty($_SESSION['authed']);

if ($authed && $action === 'unban_ip') {
    Auth::unban($_POST['ip'] ?? '');
    header('Location: ?page=settings&unbanned=1');
    exit;
}

// ── Shared setup ──────────────────────────────────────────────────────────────

$cacheDir     = __DIR__ . '/cache';
$cacheTtl     = (int) (getenv('CACHE_TTL') ?: 82800);
$ssKey        = getenv('SS_API_KEY')           ?: '';
$ssSecret     = getenv('SS_API_SECRET')        ?: '';
$shopifyToken = getenv('SHOPIFY_ACCESS_TOKEN') ?: '';

$ignoredOrders = [];
$cacheObj      = null;

if ($authed) {
    $cacheObj      = new Cache($cacheDir, $cacheTtl);
    $ignoredOrders = IgnoreList::load();
}

/** Build redirect URL from POST redirect_page / redirect_date fields. */
function redirectBack(string $defaultPage = 'reports'): string
{
    $page = $_POST['redirect_page'] ?? $defaultPage;
    $date = $_POST['redirect_date'] ?? '';
    $loc  = '?page=' . urlencode($page);
    if ($date) $loc .= '&date=' . urlencode($date);
    return $loc;
}

// ── CSV download ──────────────────────────────────────────────────────────────

if ($authed && ($_GET['action'] ?? '') === 'download') {
    $date = $_GET['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400); exit('Invalid date.');
    }
    $path = $reportDir . '/missing_' . $date . '.csv';
    if (!file_exists($path)) {
        http_response_code(404); exit('Report not found.');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="missing_' . $date . '.csv"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Ignore / unignore ─────────────────────────────────────────────────────────

if ($authed && $action === 'ignore_order') {
    IgnoreList::add(Comparator::normalise($_POST['order_number'] ?? ''), trim($_POST['reason'] ?? ''));
    header('Location: ' . redirectBack()); exit;
}

if ($authed && $action === 'unignore_order') {
    IgnoreList::remove(Comparator::normalise($_POST['order_number'] ?? ''));
    header('Location: ' . redirectBack()); exit;
}

// ── Push to ShipStation ───────────────────────────────────────────────────────

if ($authed && $action === 'push_to_shipstation') {
    $shopifyId = trim($_POST['shopify_id'] ?? '');
    $loc       = redirectBack('run');

    if (!$shopifyId || !$ssKey || !$ssSecret || !$shopifyToken) {
        $loc .= '&push_error=' . urlencode('Missing credentials or order ID.');
    } else {
        try {

            $shopify      = new Shopify($shopifyStore, $shopifyToken);
            $shopifyOrder = $shopify->getOrder($shopifyId);

            if (empty($shopifyOrder)) {
                throw new RuntimeException("Order {$shopifyId} not found in Shopify.");
            }

            $ss      = new ShipStation($ssKey, $ssSecret);
            $created = $ss->createOrder($shopifyOrder);

            $orderNum = $created['orderNumber'] ?? $shopifyId;

            PushLog::append([
                'order_number' => $orderNum,
                'shopify_id'   => $shopifyId,
                'ss_order_id'  => $created['orderId'] ?? null,
                'pushed_at'    => date('Y-m-d H:i:s'),
            ]);

            $loc .= '&push_ok=' . urlencode($orderNum);
        } catch (Throwable $e) {
            $loc .= '&push_error=' . urlencode($e->getMessage());
        }
    }

    header('Location: ' . $loc);
    exit;
}

// ── Dry-run push (preview payload without sending) ───────────────────────────

if ($authed && $action === 'preview_push') {
    $shopifyId = trim($_POST['shopify_id'] ?? '');
    header('Content-Type: application/json');

    if (!$shopifyId || !$shopifyToken) {
        echo json_encode(['error' => 'Missing credentials or order ID.']);
        exit;
    }

    try {

        $shopify      = new Shopify($shopifyStore, $shopifyToken);
        $shopifyOrder = $shopify->getOrder($shopifyId);

        if (empty($shopifyOrder)) {
            echo json_encode(['error' => "Order {$shopifyId} not found in Shopify."]);
            exit;
        }

        $ss = new ShipStation($ssKey ?: 'preview', $ssSecret ?: 'preview');
        $payload  = $ss->buildPayload($shopifyOrder);

        echo json_encode(['payload' => $payload], JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Bulk unignore ─────────────────────────────────────────────────────────────

if ($authed && $action === 'bulk_unignore_orders') {
    $numbers = array_filter((array) ($_POST['order_numbers'] ?? []));
    $norms   = array_values(array_filter(array_map([Comparator::class, 'normalise'], $numbers)));
    IgnoreList::bulkRemove($norms);
    header('Location: ?page=ignored');
    exit;
}

// ── Bulk ignore ───────────────────────────────────────────────────────────────

if ($authed && $action === 'bulk_ignore_orders') {
    $numbers = array_filter((array) ($_POST['order_numbers'] ?? []));
    $reason  = trim($_POST['reason'] ?? '');
    $entries = [];
    foreach ($numbers as $raw) {
        $norm = Comparator::normalise($raw);
        if ($norm) $entries[] = ['number' => $norm, 'reason' => $reason];
    }
    IgnoreList::bulkAdd($entries);
    header('Location: ' . redirectBack()); exit;
}

// ── CSV import bulk ignore ────────────────────────────────────────────────────

if ($authed && $action === 'import_ignore_csv') {
    $file   = $_FILES['ignore_csv'] ?? null;
    $reason = trim($_POST['import_reason'] ?? '') ?: 'CSV import ' . date('Y-m-d');
    $count  = 0;
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $count = IgnoreList::importCsv($file['tmp_name'], $reason);
    }
    header('Location: ?page=ignored&imported=' . $count);
    exit;
}

// ── Connection test ───────────────────────────────────────────────────────────

$connResults = null;

if ($authed && $action === 'test_connection') {

    $ping = function (string $url, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'SS-Shopify-Audit/1.0',
        ]);
        $t0   = microtime(true);
        curl_exec($ch);
        $ms   = (int) round((microtime(true) - $t0) * 1000);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'ms' => $ms, 'error' => $err ?: null];
    };

    if ($ssKey && $ssSecret) {
        $auth = base64_encode("{$ssKey}:{$ssSecret}");
        $connResults['ss'] = $ping(
            'https://ssapi.shipstation.com/orders?pageSize=1',
            ["Authorization: Basic {$auth}", 'Accept: application/json']
        );
    } else {
        $connResults['ss'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SS_API_KEY / SS_API_SECRET not set in .env'];
    }

    if ($shopifyToken && $shopifyStore !== 'N/A') {
        $host = str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com";
        $connResults['shopify'] = $ping(
            "https://{$host}/admin/api/2024-01/shop.json",
            ["X-Shopify-Access-Token: {$shopifyToken}", 'Accept: application/json']
        );
    } else {
        $connResults['shopify'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env'];
    }
}

// ── Cache flush ───────────────────────────────────────────────────────────────

$cacheEntries = [];
$cacheFlushed = 0;

if ($authed) {
    if ($action === 'flush_cache') {
        $cacheFlushed = $cacheObj->flush();
    }
    $cacheEntries = $cacheObj->entries();
}

// ── Push log ──────────────────────────────────────────────────────────────────

$pushLog = [];

if ($authed) {
    $pushLog = PushLog::all();
}

// ── Banned IPs ────────────────────────────────────────────────────────────────

$bannedIps = [];

if ($authed) {
    $bannedIps = Auth::bannedIps();
}

// ── Spot-check ────────────────────────────────────────────────────────────────

$spotResults = null;
$spotInput   = '';
$spotError   = '';

if ($authed && $action === 'spotcheck') {
    $spotInput = trim($_POST['orders'] ?? '');
    $numbers   = array_filter(array_map('trim', preg_split('/[\s,]+/', $spotInput)));

    if (empty($numbers)) {
        $spotError = 'Enter at least one order number.';
    } elseif (count($numbers) > 50) {
        $spotError = 'Maximum 50 order numbers at once.';
    } else {

        $spotMode = $_POST['spotcheck_mode'] ?? 'both';
        $checkSS  = in_array($spotMode, ['both', 'ss'], true);
        $checkSh  = in_array($spotMode, ['both', 'shopify'], true) && $shopifyToken && $shopifyStore !== 'N/A';

        if ($checkSS && !$ssKey || !$ssSecret) {
            $spotError = 'SS_API_KEY / SS_API_SECRET not set in .env.';
        } else {
            try {
                $ss      = $checkSS ? new ShipStation($ssKey, $ssSecret) : null;
                $shopify = $checkSh ? new Shopify($shopifyStore, $shopifyToken) : null;

                $spotResults = [];
                foreach ($numbers as $num) {
                    $clean    = ltrim(trim($num), '#');
                    $ssOrders = $ss      ? $ss->findByOrderNumber($clean)      : null;
                    $shOrders = $shopify ? $shopify->findByOrderNumber($clean)  : null;

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

// ── On-demand audit ───────────────────────────────────────────────────────────

$auditResult    = null;
$auditStart     = $_POST['audit_start'] ?? $_GET['start'] ?? date('Y-m-d', strtotime('-12 months'));
$auditEnd       = $_POST['audit_end']   ?? $_GET['end']   ?? date('Y-m-d');
$auditError     = '';
$auditDuration  = 0;
$auditFromCache = ['shopify' => false, 'ss' => false];

if ($authed && $action === 'run_audit') {
    $auditStart = $_POST['audit_start'] ?? '';
    $auditEnd   = $_POST['audit_end']   ?? '';

    $validStart = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditStart);
    $validEnd   = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditEnd);

    if (!$validStart || !$validEnd) {
        $auditError = 'Invalid date format. Use YYYY-MM-DD.';
    } elseif ($auditStart > $auditEnd) {
        $auditError = 'Start date must be before end date.';
    } elseif (false) { // no date range limit
        $auditError = '';
    } else {

        if (!$ssKey || !$ssSecret || !$shopifyToken) {
            $auditError = 'API credentials missing in .env.';
        } else {

            try {
                set_time_limit(600);
                ini_set('memory_limit', '512M');
                $t0 = microtime(true);

                // SS end date is extended by 7 days to catch sub-orders
                // that are created in ShipStation a few days after the Shopify order.
                $ssAuditEnd = date('Y-m-d', strtotime($auditEnd . ' +7 days'));

                $auditFromCache = [
                    'shopify' => $cacheObj->isFresh('shopify', "{$auditStart}|{$auditEnd}"),
                    'ss'      => $cacheObj->isFresh('ss',      "{$auditStart}|{$ssAuditEnd}"),
                ];

                $ss      = new ShipStation($ssKey, $ssSecret, $cacheObj);
                $shopify = new Shopify($shopifyStore, $shopifyToken, $cacheObj);

                ob_start();
                $shopifyOrders = $shopify->fetchAllOrders($auditStart, $auditEnd);
                $ssOrders      = $ss->fetchAllOrders($auditStart, $ssAuditEnd);
                ob_end_clean();

                $ssIndex      = Comparator::buildSSIndex($ssOrders);
                $ssEmailIndex = Comparator::buildSSEmailIndex($ssOrders);
                $comparison   = Comparator::compare($shopifyOrders, $ssIndex, $ignoredOrders, $ssEmailIndex);

                Reporter::saveReports($comparison['missing'], $auditStart, $auditEnd);

                $auditDuration = round(microtime(true) - $t0, 1);
                $auditResult   = [
                    'missing'  => $comparison['missing'],
                    'ignored'  => $comparison['ignored'],
                    'found'    => count($comparison['found']),
                    'skipped'  => count($comparison['skipped']),
                    'total_ss' => count($ssOrders),
                ];

                $cacheEntries = $cacheObj->entries();
            } catch (Throwable $e) {
                $auditError = $e->getMessage();
            }
        }
    }
}

// ── Load report data ──────────────────────────────────────────────────────────

$reports      = [];
$latestReport = null;
$orderHistory = []; // [norm_num => ['count' => N, 'first' => date, 'last' => date]]

if ($authed && is_dir($reportDir)) {
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
            fn($o) => !isset($ignoredOrders[Comparator::normalise((string)($o['order_number'] ?? ''))])
        ));

        $reports[] = ['date' => $date, 'csvPath' => $csvPath, 'missing' => $missing, 'count' => count($missing)];
    }

    $latestReport = $reports[0] ?? null;
}

$selectedDate   = $_GET['date'] ?? ($latestReport['date'] ?? null);
$selectedReport = null;
foreach ($reports as $r) {
    if ($r['date'] === $selectedDate) { $selectedReport = $r; break; }
}

$shopifyAdminBase = 'https://'
    . (str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com")
    . '/admin/orders';

// ── Prefill spot-check from Re-check link ─────────────────────────────────────

if ($authed && ($_GET['page'] ?? '') === 'spotcheck' && isset($_GET['prefill'])) {
    $spotInput = trim($_GET['prefill']);
}

// ── Render ────────────────────────────────────────────────────────────────────

if (!$authed) {
    require __DIR__ . '/views/login.php';
} else {
    $page = $_GET['page'] ?? 'reports';
    require __DIR__ . '/views/layout.php';
}
