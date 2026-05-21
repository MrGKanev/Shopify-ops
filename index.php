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
require_once __DIR__ . '/src/Actions.php';
require_once __DIR__ . '/src/PageLoader.php';

Env::load(__DIR__ . '/.env');

$webUsername    = getenv('WEB_USERNAME')        ?: 'admin';
$webPassword    = getenv('WEB_PASSWORD')        ?: 'changeme';
$shopifyStore   = getenv('SHOPIFY_STORE')       ?: 'N/A';
$shopifyToken   = getenv('SHOPIFY_ACCESS_TOKEN')?: '';
$ssKey          = getenv('SS_API_KEY')          ?: '';
$ssSecret       = getenv('SS_API_SECRET')       ?: '';
$appTitle       = getenv('APP_TITLE')           ?: 'Shopify Ops';
$appBrand       = getenv('APP_BRAND')           ?: 'Shopify Ops';
$appLogo        = getenv('APP_LOGO')            ?: '';
$appStoreNumber = getenv('APP_STORE_NUMBER')    ?: '';
$cacheTtl       = (int) (getenv('CACHE_TTL')   ?: 82800);
$reportDir      = __DIR__ . '/reports';

// ── Session / auth ────────────────────────────────────────────────────────────

session_start();

$action = $_POST['action'] ?? '';
$error  = '';

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

// ── Shared setup ──────────────────────────────────────────────────────────────

$cacheObj      = null;
$ignoredOrders = [];

if ($authed) {
    $cacheObj      = new Cache(__DIR__ . '/cache', $cacheTtl);
    $ignoredOrders = IgnoreList::load();
}

// ── Dispatch early-exit actions ───────────────────────────────────────────────

$ctx = compact('authed', 'action', 'ssKey', 'ssSecret', 'shopifyToken', 'shopifyStore',
               'cacheObj', 'cacheTtl', 'reportDir', 'ignoredOrders');

Actions::dispatch($action, $ctx);

// ── Load page data ────────────────────────────────────────────────────────────

$page = $_GET['page'] ?? 'reports';

extract(PageLoader::load($page, $action, $ctx));

// ── Render ────────────────────────────────────────────────────────────────────

if (!$authed) {
    require __DIR__ . '/views/login.php';
} else {
    require __DIR__ . '/views/layout.php';
}
