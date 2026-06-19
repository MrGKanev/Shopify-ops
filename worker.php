#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Stores.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/IgnoreList.php';
require_once __DIR__ . '/src/RunLog.php';
require_once __DIR__ . '/src/SlackRules.php';
require_once __DIR__ . '/src/SlackNotifier.php';
require_once __DIR__ . '/src/JobQueue.php';
require_once __DIR__ . '/src/ShipStation.php';
require_once __DIR__ . '/src/Shopify.php';
require_once __DIR__ . '/src/Comparator.php';
require_once __DIR__ . '/src/Reporter.php';

Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();
Stores::init(__DIR__);

$args = $argv ?? [];
$storeId = '';
foreach ($args as $i => $arg) {
    if ($arg === '--store' && isset($args[$i + 1])) {
        $storeId = (string)$args[$i + 1];
    }
}

$config = resolveWorkerStore($storeId);
configureWorkerDataDirs($config['store_id']);

$job = JobQueue::claimNext();
if ($job === null) {
    echo "No pending jobs.\n";
    exit(0);
}

echo "Running job {$job['id']} ({$job['type']})...\n";

try {
    $result = match ($job['type']) {
        'audit' => runAuditJob($job['payload'] ?? [], $config),
        default => throw new RuntimeException("Unsupported job type: {$job['type']}"),
    };
    JobQueue::complete($job['id'], $result);
    echo "Done.\n";
    exit(0);
} catch (Throwable $e) {
    JobQueue::fail($job['id'], $e->getMessage());
    Logger::getInstance(__DIR__ . '/logs')->error('Worker job failed: {message}', [
        'message' => $e->getMessage(),
        'exception' => $e->getFile() . ':' . $e->getLine(),
    ]);
    echo "Failed: {$e->getMessage()}\n";
    exit(1);
}

/**
 * @return array<string, string>
 */
function resolveWorkerStore(string $storeId): array
{
    if (Stores::isMultiStore()) {
        $stores = Stores::all();
        $store = $stores[0] ?? [];
        foreach ($stores as $candidate) {
            if (($candidate['id'] ?? '') === $storeId) {
                $store = $candidate;
                break;
            }
        }
        return [
            'store_id' => (string)($store['id'] ?? 'default'),
            'shopify_store' => (string)($store['shopify_store'] ?? 'N/A'),
            'shopify_token' => (string)($store['shopify_token'] ?? ''),
            'ss_key' => (string)($store['ss_key'] ?? ''),
            'ss_secret' => (string)($store['ss_secret'] ?? ''),
        ];
    }

    return [
        'store_id' => '',
        'shopify_store' => (string)(getenv('SHOPIFY_STORE') ?: 'N/A'),
        'shopify_token' => (string)(getenv('SHOPIFY_ACCESS_TOKEN') ?: ''),
        'ss_key' => (string)(getenv('SS_API_KEY') ?: ''),
        'ss_secret' => (string)(getenv('SS_API_SECRET') ?: ''),
    ];
}

function configureWorkerDataDirs(string $storeId): void
{
    if ($storeId === '') return;
    $dataDir = __DIR__ . '/data/' . $storeId;
    IgnoreList::setDataDir($dataDir);
    RunLog::setDataDir($dataDir);
    SlackRules::setDataDir($dataDir);
    JobQueue::setDataDir($dataDir);
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, string> $config
 * @return array<string, mixed>
 */
function runAuditJob(array $payload, array $config): array
{
    $start = (string)($payload['start'] ?? date('Y-m-d', strtotime('-90 days')));
    $end = (string)($payload['end'] ?? date('Y-m-d'));

    foreach (['shopify_token', 'ss_key', 'ss_secret'] as $required) {
        if (($config[$required] ?? '') === '') {
            throw new RuntimeException("Missing required credential: {$required}");
        }
    }

    $storeId = $config['store_id'] ?? '';
    $cacheTtl = (int)(getenv('CACHE_TTL') ?: 82800);
    $cacheRetention = (int)(getenv('CACHE_RETENTION') ?: 1209600);
    $cacheDir = __DIR__ . '/cache' . ($storeId ? "/{$storeId}" : '');
    $reportDir = __DIR__ . '/reports' . ($storeId ? "/{$storeId}" : '');
    $cache = new Cache($cacheDir, $cacheTtl, $cacheRetention);

    $t0 = microtime(true);
    $ssEnd = date('Y-m-d', strtotime($end . ' +7 days'));
    $shopify = new Shopify($config['shopify_store'], $config['shopify_token'], $cache);
    $ss = new ShipStation($config['ss_key'], $config['ss_secret'], $cache);

    $shopifyOrders = $shopify->fetchAllOrders($start, $end);
    $ssOrders = $ss->fetchAllOrders($start, $ssEnd);
    $comparison = Comparator::compare(
        $shopifyOrders,
        Comparator::buildSSIndex($ssOrders),
        IgnoreList::load(),
        Comparator::buildSSEmailIndex($ssOrders)
    );

    if (!empty($comparison['missing'])) {
        $stillMissing = [];
        foreach ($comparison['missing'] as $order) {
            if ($shopify->isOnHold((string)($order['id'] ?? ''))) {
                $order['_skip_reason'] = 'on_hold';
                $comparison['skipped'][] = $order;
            } else {
                $stillMissing[] = $order;
            }
        }
        $comparison['missing'] = $stillMissing;
    }

    foreach ($comparison['missing'] as &$order) {
        $order['_order_type'] = Comparator::classifyOrder($order);
    }
    unset($order);

    Reporter::saveReports($comparison['missing'], $start, $end, $reportDir);
    $duration = round(microtime(true) - $t0, 2);

    if (SlackRules::shouldNotifyAudit(count($comparison['missing'])) && ($notifier = SlackNotifier::fromEnvironment())) {
        $notifier->notifyAuditSafely([
            'store' => $config['shopify_store'],
            'start' => $start,
            'end' => $end,
            'duration' => $duration,
            'missing_count' => count($comparison['missing']),
            'missing_orders' => $comparison['missing'],
            'found' => count($comparison['found']),
            'skipped' => count($comparison['skipped']),
            'ignored' => count($comparison['ignored']),
            'total_ss' => count($ssOrders),
        ], Logger::getInstance(__DIR__ . '/logs'));
    }

    RunLog::append([
        'tool' => 'queued_audit',
        'status' => count($comparison['missing']) > 0 ? 'issues_found' : 'ok',
        'duration' => $duration,
        'start_date' => $start,
        'end_date' => $end,
        'scanned' => count($shopifyOrders),
        'rows_found' => count($comparison['missing']),
        'meta' => [
            'api_version' => Shopify::API_VERSION,
            'shipstation_total' => count($ssOrders),
            'found' => count($comparison['found']),
            'skipped' => count($comparison['skipped']),
            'ignored' => count($comparison['ignored']),
        ],
    ]);

    return [
        'missing' => count($comparison['missing']),
        'found' => count($comparison['found']),
        'skipped' => count($comparison['skipped']),
        'ignored' => count($comparison['ignored']),
        'duration' => $duration,
    ];
}
