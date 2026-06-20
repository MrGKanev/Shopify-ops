<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/SlackRules.php';
require_once __DIR__ . '/../../src/SlackNotifier.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ShipStation.php';
require_once __DIR__ . '/../../src/ScanRunner.php';
require_once __DIR__ . '/../../src/ProductInventoryPageLoader.php';

use PHPUnit\Framework\TestCase;

class ProductInventoryPageLoaderTest extends TestCase
{
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private string|false $previousSlackWebhook;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/product_inventory_loader_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        RunLog::setDataDir($this->tmpDir);
        SlackRules::setDataDir($this->tmpDir);

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

        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);

        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
    }

    public function testBundleCheckInitialStateUsesRequestRange(): void
    {
        $_GET = ['bc_start' => '2026-06-01', 'bc_end' => '2026-06-20'];

        $data = ProductInventoryPageLoader::load('bundlecheck', '', $this->ctx());

        $this->assertNull($data['bcResult']);
        $this->assertSame('', $data['bcError']);
        $this->assertSame('2026-06-01', $data['bcStart']);
        $this->assertSame('2026-06-20', $data['bcEnd']);
        $this->assertIsArray($data['bcConfig']);
        $this->assertSame([], RunLog::all());
    }

    public function testBundleCheckMissingShopifyCredentials(): void
    {
        $_POST = ['bc_start' => '2026-06-01', 'bc_end' => '2026-06-20'];

        $data = ProductInventoryPageLoader::load('bundlecheck', 'scan_bundle', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['bcResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['bcError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testProductCheckInitialAndMissingShopifyCredentials(): void
    {
        $initial = ProductInventoryPageLoader::load('productcheck', '', $this->ctx());

        $this->assertNull($initial['pcResult']);
        $this->assertSame('', $initial['pcError']);

        $submitted = ProductInventoryPageLoader::load('productcheck', 'scan_products', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['pcResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['pcError']);
    }

    public function testSkuDupesInitialAndMissingShopifyCredentials(): void
    {
        $initial = ProductInventoryPageLoader::load('skudupes', '', $this->ctx());

        $this->assertNull($initial['sdResult']);
        $this->assertSame('', $initial['sdError']);

        $submitted = ProductInventoryPageLoader::load('skudupes', 'scan_skudupes', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['sdResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['sdError']);
    }

    public function testInventoryOversellChecksShopifyBeforeShipStationCredentials(): void
    {
        $missingShopify = ProductInventoryPageLoader::load('inventoryoversell', 'scan_inventory', $this->ctx([
            'shopifyToken' => '',
            'shopifyStore' => 'N/A',
            'ssKey'        => '',
            'ssSecret'     => '',
        ]));

        $this->assertNull($missingShopify['ioResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $missingShopify['ioError']);

        $missingShipStation = ProductInventoryPageLoader::load('inventoryoversell', 'scan_inventory', $this->ctx([
            'ssKey'    => '',
            'ssSecret' => '',
        ]));

        $this->assertNull($missingShipStation['ioResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $missingShipStation['ioError']);
    }

    public function testZombieProductsInitialAndMissingShopifyCredentials(): void
    {
        $initial = ProductInventoryPageLoader::load('zombieproducts', '', $this->ctx());

        $this->assertNull($initial['zpResult']);
        $this->assertSame('', $initial['zpError']);

        $submitted = ProductInventoryPageLoader::load('zombieproducts', 'scan_zombieproducts', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['zpResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['zpError']);
    }

    public function testInventoryAgingInitialRangeAndMissingShopifyCredentials(): void
    {
        $_GET = ['ia_start' => '2026-05-01', 'ia_end' => '2026-06-20'];

        $initial = ProductInventoryPageLoader::load('inventoryaging', '', $this->ctx());

        $this->assertNull($initial['iaResult']);
        $this->assertSame('', $initial['iaError']);
        $this->assertSame('2026-05-01', $initial['iaStart']);
        $this->assertSame('2026-06-20', $initial['iaEnd']);

        $_GET = [];
        $_POST = ['ia_start' => '2026-05-01', 'ia_end' => '2026-06-20'];
        $submitted = ProductInventoryPageLoader::load('inventoryaging', 'scan_inventoryaging', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['iaResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['iaError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], ProductInventoryPageLoader::load('unknown', '', $this->ctx()));
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'shopifyToken' => 'tok_test',
            'shopifyStore' => 'test.myshopify.com',
            'ssKey'        => 'ss_key',
            'ssSecret'     => 'ss_secret',
            'cacheObj'     => null,
        ];
    }
}
