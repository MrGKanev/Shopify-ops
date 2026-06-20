<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Cache.php';
require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/SlackRules.php';
require_once __DIR__ . '/../../src/SlackNotifier.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/ConfigValidator.php';
require_once __DIR__ . '/../../src/JobQueue.php';
require_once __DIR__ . '/../../src/PushLog.php';
require_once __DIR__ . '/../../src/UserActionLog.php';
require_once __DIR__ . '/../../src/ShipStation.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ApiHealth.php';
require_once __DIR__ . '/../../src/Reporter.php';
require_once __DIR__ . '/../../src/ScanRunner.php';
require_once __DIR__ . '/../../src/ManageSettingsPageLoader.php';
require_once __DIR__ . '/../../src/SearchLookupPageLoader.php';
require_once __DIR__ . '/../../src/PackingSlipPageLoader.php';
require_once __DIR__ . '/../../src/SimpleScanPageLoader.php';
require_once __DIR__ . '/../../src/FulfillmentIssuePageLoader.php';
require_once __DIR__ . '/../../src/ProductInventoryPageLoader.php';
require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';
require_once __DIR__ . '/../../src/OrderPolicyPageLoader.php';
require_once __DIR__ . '/../../src/OrderInsightPageLoader.php';
require_once __DIR__ . '/../../src/PageLoader.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FlowErrorMatrixTest extends TestCase
{
    private const SHOPIFY_ERROR = 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.';
    private const SS_ERROR = 'SS_API_KEY / SS_API_SECRET not set in .env.';
    private const SS_LEGACY_ERROR = 'SHIPSTATION_API_KEY / SHIPSTATION_API_SECRET not set in .env.';

    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private string|false $previousSlackWebhook;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/flow_error_matrix_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        RunLog::setDataDir($this->tmpDir);
        SlackRules::setDataDir($this->tmpDir);
        JobQueue::setDataDir($this->tmpDir);
        PushLog::setDataDir($this->tmpDir);
        UserActionLog::setDataDir($this->tmpDir);

        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_GET = [];
        $_POST = [];

        $this->previousSlackWebhook = getenv('SLACK_WEBHOOK_URL');
        putenv('SLACK_WEBHOOK_URL');
    }

    protected function tearDown(): void
    {
        if ($this->previousSlackWebhook === false) {
            putenv('SLACK_WEBHOOK_URL');
        } else {
            putenv('SLACK_WEBHOOK_URL=' . $this->previousSlackWebhook);
        }

        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
        $this->removeDir($this->tmpDir);
    }

    /**
     * @param array{
     *     loader: string,
     *     page?: string,
     *     action: string,
     *     post: array<string, string>,
     *     error: string,
     *     expected: string,
     *     ctx?: array<string, mixed>
     * } $flow
     */
    #[DataProvider('submittedFlowErrors')]
    public function testSubmittedFlowsSurfaceExpectedErrorsWithoutThrowing(array $flow): void
    {
        $_POST = $flow['post'];

        try {
            $data = $this->loadFlow($flow);
        } catch (Throwable $e) {
            $this->fail(sprintf(
                '%s threw %s: %s',
                $flow['page'] ?? $flow['loader'],
                $e::class,
                $e->getMessage()
            ));
        }

        $this->assertArrayHasKey($flow['error'], $data);
        $this->assertSame($flow['expected'], $data[$flow['error']]);
    }

    public function testSettingsAndApiHealthCredentialErrorsAreGroupedInKnownFields(): void
    {
        $settings = ManageSettingsPageLoader::load('settings', 'test_connection', $this->baseCtx());

        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env', $settings['connResults']['ss']['error']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env', $settings['connResults']['shopify']['error']);

        $apiHealth = ManageSettingsPageLoader::load('apihealth', 'refresh_api_health', $this->baseCtx());

        $this->assertSame('SS_API_KEY / SS_API_SECRET not set.', $apiHealth['apiHealth']['shipstation']['error']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set.', $apiHealth['apiHealth']['shopify']['error']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function submittedFlowErrors(): array
    {
        return [
            'run audit missing API credentials' => [[
                'loader' => PageLoader::class,
                'page' => 'run',
                'action' => 'run_audit',
                'post' => self::datePost('audit'),
                'error' => 'auditError',
                'expected' => 'API credentials missing in .env.',
            ]],
            'tag audit missing Shopify credentials' => [[
                'loader' => SimpleScanPageLoader::class,
                'page' => 'tagaudit',
                'action' => 'tag_audit',
                'post' => self::datePost('ta'),
                'error' => 'tagAuditError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'email check missing Shopify credentials' => [[
                'loader' => SimpleScanPageLoader::class,
                'page' => 'emailcheck',
                'action' => 'scan_emails',
                'post' => self::datePost('email'),
                'error' => 'emailError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'high value orders missing Shopify credentials' => [[
                'loader' => SimpleScanPageLoader::class,
                'page' => 'hvorders',
                'action' => 'scan_hvorders',
                'post' => self::datePost('hv') + ['hv_min' => '200'],
                'error' => 'hvError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'country mismatch missing Shopify credentials' => [[
                'loader' => SimpleScanPageLoader::class,
                'page' => 'countrymismatch',
                'action' => 'scan_country_mismatch',
                'post' => self::datePost('cm'),
                'error' => 'cmError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'partial fulfill missing Shopify credentials' => [[
                'loader' => SimpleScanPageLoader::class,
                'page' => 'partialfulfill',
                'action' => 'scan_partial_fulfill',
                'post' => self::datePost('pf') + ['pf_threshold' => '7'],
                'error' => 'pfError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'address check missing Shopify credentials' => [[
                'loader' => OrderAnomalyPageLoader::class,
                'page' => 'addrcheck',
                'action' => 'scan_addresses',
                'post' => self::datePost('addr'),
                'error' => 'addrError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'refunds missing Shopify credentials' => [[
                'loader' => OrderAnomalyPageLoader::class,
                'page' => 'refunds',
                'action' => 'find_refunds',
                'post' => self::datePost('refunds'),
                'error' => 'refundsError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'duplicates missing Shopify credentials' => [[
                'loader' => OrderAnomalyPageLoader::class,
                'page' => 'dupes',
                'action' => 'find_dupes',
                'post' => self::datePost('dupes'),
                'error' => 'dupesError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'orphans missing ShipStation credentials first' => [[
                'loader' => OrderAnomalyPageLoader::class,
                'page' => 'orphans',
                'action' => 'find_orphans',
                'post' => self::datePost('orphan'),
                'error' => 'orphanError',
                'expected' => self::SS_ERROR,
            ]],
            'repeat refunds missing Shopify credentials' => [[
                'loader' => OrderAnomalyPageLoader::class,
                'page' => 'repeatrefunds',
                'action' => 'scan_repeat_refunds',
                'post' => self::datePost('rr') + ['rr_min_count' => '2'],
                'error' => 'rrError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'failed shipments missing ShipStation credentials' => [[
                'loader' => OrderAnomalyPageLoader::class,
                'page' => 'failedship',
                'action' => 'scan_failed_shipments',
                'post' => self::datePost('fs'),
                'error' => 'fsError',
                'expected' => self::SS_LEGACY_ERROR,
            ]],
            'address changes missing Shopify credentials' => [[
                'loader' => OrderAnomalyPageLoader::class,
                'page' => 'addrchanges',
                'action' => 'scan_addr_changes',
                'post' => self::datePost('ac'),
                'error' => 'acError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'order edits missing Shopify credentials' => [[
                'loader' => OrderPolicyPageLoader::class,
                'page' => 'orderedits',
                'action' => 'scan_order_edits',
                'post' => self::datePost('oe'),
                'error' => 'oeError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'note flags missing Shopify credentials' => [[
                'loader' => OrderPolicyPageLoader::class,
                'page' => 'noteflags',
                'action' => 'scan_noteflags',
                'post' => self::datePost('nf') + ['nf_keywords' => 'hold'],
                'error' => 'nfError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'address dupes missing Shopify credentials' => [[
                'loader' => OrderPolicyPageLoader::class,
                'page' => 'addrdupes',
                'action' => 'scan_addrdupes',
                'post' => self::datePost('ad'),
                'error' => 'adError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'active ShipStation conflicts missing ShipStation credentials first' => [[
                'loader' => OrderPolicyPageLoader::class,
                'page' => 'activess',
                'action' => 'scan_activess',
                'post' => self::datePost('as'),
                'error' => 'asError',
                'expected' => self::SS_ERROR,
            ]],
            'discount abuse missing Shopify credentials' => [[
                'loader' => OrderPolicyPageLoader::class,
                'page' => 'discountabuse',
                'action' => 'scan_discountabuse',
                'post' => self::datePost('da') + ['da_min_emails' => '3'],
                'error' => 'daError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'tag policy missing Shopify credentials' => [[
                'loader' => OrderPolicyPageLoader::class,
                'page' => 'tagpolicy',
                'action' => 'scan_tagpolicy',
                'post' => self::datePost('tp'),
                'error' => 'tpError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'on hold stall missing Shopify credentials' => [[
                'loader' => FulfillmentIssuePageLoader::class,
                'page' => 'onholdstall',
                'action' => 'scan_onhold',
                'post' => self::datePost('oh'),
                'error' => 'ohError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'no tracking missing Shopify credentials' => [[
                'loader' => FulfillmentIssuePageLoader::class,
                'page' => 'notracking',
                'action' => 'scan_notracking',
                'post' => self::datePost('nt') + ['nt_threshold' => '24'],
                'error' => 'ntError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'post-ship address changes missing Shopify credentials' => [[
                'loader' => FulfillmentIssuePageLoader::class,
                'page' => 'postshipaddr',
                'action' => 'scan_postshipaddr',
                'post' => self::datePost('ps'),
                'error' => 'psError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'ShipStation shipped unfulfilled missing ShipStation credentials first' => [[
                'loader' => FulfillmentIssuePageLoader::class,
                'page' => 'ssshipped',
                'action' => 'scan_ssshipped',
                'post' => self::datePost('ssu'),
                'error' => 'ssuError',
                'expected' => self::SS_ERROR,
            ]],
            'SLA breaches missing Shopify credentials' => [[
                'loader' => FulfillmentIssuePageLoader::class,
                'page' => 'slabreaches',
                'action' => 'scan_sla',
                'post' => self::datePost('sla') + ['sla_threshold' => '3'],
                'error' => 'slaError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'shipment aging missing ShipStation credentials' => [[
                'loader' => FulfillmentIssuePageLoader::class,
                'page' => 'shipmentaging',
                'action' => 'scan_shipmentaging',
                'post' => ['sa_threshold' => '3'],
                'error' => 'saError',
                'expected' => self::SS_ERROR,
            ]],
            'bundle check missing Shopify credentials' => [[
                'loader' => ProductInventoryPageLoader::class,
                'page' => 'bundlecheck',
                'action' => 'scan_bundle',
                'post' => self::datePost('bc'),
                'error' => 'bcError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'product check missing Shopify credentials' => [[
                'loader' => ProductInventoryPageLoader::class,
                'page' => 'productcheck',
                'action' => 'scan_products',
                'post' => [],
                'error' => 'pcError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'SKU dupes missing Shopify credentials' => [[
                'loader' => ProductInventoryPageLoader::class,
                'page' => 'skudupes',
                'action' => 'scan_skudupes',
                'post' => [],
                'error' => 'sdError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'inventory oversell missing Shopify credentials first' => [[
                'loader' => ProductInventoryPageLoader::class,
                'page' => 'inventoryoversell',
                'action' => 'scan_inventory',
                'post' => [],
                'error' => 'ioError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'zombie products missing Shopify credentials' => [[
                'loader' => ProductInventoryPageLoader::class,
                'page' => 'zombieproducts',
                'action' => 'scan_zombieproducts',
                'post' => [],
                'error' => 'zpError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'inventory aging missing Shopify credentials' => [[
                'loader' => ProductInventoryPageLoader::class,
                'page' => 'inventoryaging',
                'action' => 'scan_inventoryaging',
                'post' => self::datePost('ia'),
                'error' => 'iaError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'tag search missing Shopify credentials' => [[
                'loader' => SearchLookupPageLoader::class,
                'page' => 'tagsearch',
                'action' => 'tag_search',
                'post' => ['tag_input' => 'vip'],
                'error' => 'tagSearchError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'spot check missing ShipStation credentials' => [[
                'loader' => SearchLookupPageLoader::class,
                'page' => 'spotcheck',
                'action' => 'spotcheck',
                'post' => ['orders' => '1001', 'spotcheck_mode' => 'both'],
                'error' => 'spotError',
                'expected' => self::SS_ERROR,
            ]],
            'metafields missing Shopify credentials' => [[
                'loader' => SearchLookupPageLoader::class,
                'page' => 'metafields',
                'action' => 'metafield_lookup',
                'post' => ['mf_orders' => '1001'],
                'error' => 'metafieldError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'customer lookup missing Shopify credentials' => [[
                'loader' => SearchLookupPageLoader::class,
                'page' => 'customer',
                'action' => 'customer_lookup',
                'post' => ['customer_email' => 'customer@example.com'],
                'error' => 'customerError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'tracking lookup missing ShipStation credentials' => [[
                'loader' => SearchLookupPageLoader::class,
                'page' => 'tracking',
                'action' => 'lookup_tracking',
                'post' => ['tracking_orders' => '1001'],
                'error' => 'trackingError',
                'expected' => self::SS_ERROR,
            ]],
            'compare orders missing Shopify credentials' => [[
                'loader' => OrderInsightPageLoader::class,
                'page' => 'compare',
                'action' => 'compare_orders',
                'post' => ['compare_a' => '1001', 'compare_b' => '1002'],
                'error' => 'compareError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'order timeline missing Shopify credentials' => [[
                'loader' => OrderInsightPageLoader::class,
                'page' => 'timeline',
                'action' => 'order_timeline',
                'post' => ['tl_order' => '1001'],
                'error' => 'tlError',
                'expected' => self::SHOPIFY_ERROR,
            ]],
            'packing slip missing ShipStation credentials' => [[
                'loader' => PackingSlipPageLoader::class,
                'action' => 'packingslip',
                'post' => ['order_number' => '1001'],
                'error' => 'slipError',
                'expected' => self::SS_ERROR,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $flow
     * @return array<string, mixed>
     */
    private function loadFlow(array $flow): array
    {
        $ctx = $this->baseCtx($flow['ctx'] ?? []);
        $loader = $flow['loader'];

        if ($loader === PageLoader::class) {
            return PageLoader::load($flow['page'], $flow['action'], $ctx);
        }

        if ($loader === PackingSlipPageLoader::class) {
            return PackingSlipPageLoader::load($flow['action'], $ctx);
        }

        return $loader::load($flow['page'], $flow['action'], $ctx);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseCtx(array $overrides = []): array
    {
        return $overrides + [
            'authed'        => true,
            'action'        => '',
            'shopifyStore'  => 'N/A',
            'shopifyToken'  => '',
            'ssKey'         => '',
            'ssSecret'      => '',
            'cacheObj'      => new Cache($this->tmpDir . '/cache', ttl: 3600),
            'cacheTtl'      => 3600,
            'reportDir'     => $this->tmpDir . '/reports',
            'ignoredOrders' => [],
            'appVersion'    => 'test',
            'storeId'       => '',
            'storeLabel'    => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function datePost(string $prefix): array
    {
        return [
            "{$prefix}_start" => '2026-06-01',
            "{$prefix}_end"   => '2026-06-20',
        ];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
