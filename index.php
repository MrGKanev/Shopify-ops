<?php
declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Stores.php';
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

Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();
Stores::init(__DIR__);

$log = Logger::getInstance(__DIR__ . '/logs');

set_exception_handler(function (\Throwable $e) use ($log): void {
    $log->error('Uncaught {class}: {message}', [
        'class'     => get_class($e),
        'message'   => $e->getMessage(),
        'exception' => $e->getFile() . ':' . $e->getLine(),
    ]);
    http_response_code(500);
    echo '<h1>Something went wrong.</h1>';
    exit(1);
});

set_error_handler(function (int $severity, string $message, string $file, int $line) use ($log): bool {
    if (!($severity & error_reporting())) return false;
    $log->warning('PHP error [{severity}]: {message}', [
        'severity' => $severity,
        'message'  => $message,
        'exception' => $file . ':' . $line,
    ]);
    return false;
});

// ── Session / auth ────────────────────────────────────────────────────────────

session_start();

$action      = $_POST['action'] ?? '';
$error       = '';
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);

if ($action === 'login') {
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $error = Auth::attempt($_POST['username'] ?? '', $_POST['password'] ?? '', getenv('WEB_USERNAME') ?: 'admin', getenv('WEB_PASSWORD') ?: 'changeme', $ip);
    if ($error === '') {
        $_SESSION['authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

if ($action === 'dev_login' && $isLocalhost) {
    $_SESSION['authed'] = true;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($action === 'logout') {
    Auth::logout();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$authed = !empty($_SESSION['authed']);

// ── Store config (multi-store or single .env) ─────────────────────────────────

if (Stores::isMultiStore()) {
    $activeStore    = Stores::getActive();
    $shopifyStore   = $activeStore['shopify_store']  ?? 'N/A';
    $shopifyToken   = $activeStore['shopify_token']  ?? '';
    $ssKey          = $activeStore['ss_key']         ?? '';
    $ssSecret       = $activeStore['ss_secret']      ?? '';
    $appStoreNumber = $activeStore['store_number']   ?? '';
    $storeId        = $activeStore['id']             ?? 'default';
    $storeLabel     = $activeStore['label']          ?? $shopifyStore;
    $allStores      = Stores::all();
} else {
    $shopifyStore   = getenv('SHOPIFY_STORE')        ?: 'N/A';
    $shopifyToken   = getenv('SHOPIFY_ACCESS_TOKEN') ?: '';
    $ssKey          = getenv('SS_API_KEY')           ?: '';
    $ssSecret       = getenv('SS_API_SECRET')        ?: '';
    $appStoreNumber = getenv('APP_STORE_NUMBER')     ?: '';
    $storeId        = '';
    $storeLabel     = '';
    $allStores      = [];
}

$webUsername   = getenv('WEB_USERNAME') ?: 'admin';
$webPassword   = getenv('WEB_PASSWORD') ?: 'changeme';
$_appTitleEnv  = getenv('APP_TITLE') ?: '';
$appTitle      = $_appTitleEnv ? "{$_appTitleEnv} - Shopify OPS" : 'Shopify OPS';
$appBrand      = $_appTitleEnv ?: 'Shopify OPS';
$appLogo       = getenv('APP_LOGO') ?: '';
$appVersion    = json_decode((string) file_get_contents(__DIR__ . '/composer.json'), true)['version'] ?? 'dev';
$cacheTtl       = (int) (getenv('CACHE_TTL')           ?: 82800);   // data validity, default 23 h
$cacheRetention = (int) (getenv('CACHE_RETENTION')    ?: 1209600); // keep on disk after expiry, default 2 weeks

$reportDir = __DIR__ . '/reports' . ($storeId ? "/{$storeId}" : '');
$cacheDir  = __DIR__ . '/cache'   . ($storeId ? "/{$storeId}" : '');
$dataDir   = __DIR__ . '/data'    . ($storeId ? "/{$storeId}" : '');

// ── Shared setup ──────────────────────────────────────────────────────────────

$cacheObj      = null;
$ignoredOrders = [];

if ($authed) {
    $cacheObj = new Cache($cacheDir, $cacheTtl, $cacheRetention);
    if ($storeId) {
        IgnoreList::setDataDir($dataDir);
        PushLog::setDataDir($dataDir);
    }
    $ignoredOrders = IgnoreList::load();
}

// ── Dispatch early-exit actions ───────────────────────────────────────────────

$ctx = compact('authed', 'action', 'ssKey', 'ssSecret', 'shopifyToken', 'shopifyStore',
               'cacheObj', 'cacheTtl', 'reportDir', 'ignoredOrders', 'appVersion');

Actions::dispatch($action, $ctx);

// ── Load page data ────────────────────────────────────────────────────────────

$page = $_GET['page'] ?? 'dashboard';

extract(PageLoader::load($page, $action, $ctx), EXTR_SKIP);

// ── Render ────────────────────────────────────────────────────────────────────

if (!$authed) {
    require __DIR__ . '/views/login.php';
} else {
    require __DIR__ . '/views/layout.php';
}
