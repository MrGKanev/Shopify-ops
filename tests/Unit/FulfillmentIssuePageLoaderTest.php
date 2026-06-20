<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/SlackRules.php';
require_once __DIR__ . '/../../src/SlackNotifier.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ScanRunner.php';
require_once __DIR__ . '/../../src/FulfillmentIssuePageLoader.php';

use PHPUnit\Framework\TestCase;

class FulfillmentIssuePageLoaderTest extends TestCase
{
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private string|false $previousSlackWebhook;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fulfillment_issue_loader_' . uniqid();
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

    public function testOnHoldInitialStateUsesRequestRange(): void
    {
        $_GET = ['oh_start' => '2026-06-01', 'oh_end' => '2026-06-20'];

        $data = FulfillmentIssuePageLoader::load('onholdstall', '', $this->ctx());

        $this->assertNull($data['ohResult']);
        $this->assertSame('', $data['ohError']);
        $this->assertSame('2026-06-01', $data['ohStart']);
        $this->assertSame('2026-06-20', $data['ohEnd']);
    }

    public function testNoTrackingCarriesThresholdAndMissingShopifyCredentials(): void
    {
        $_GET = ['nt_threshold' => '36'];
        $initial = FulfillmentIssuePageLoader::load('notracking', '', $this->ctx());

        $this->assertNull($initial['ntResult']);
        $this->assertSame('', $initial['ntError']);
        $this->assertSame(36, $initial['ntThreshold']);

        $_GET = [];
        $_POST = ['nt_start' => '2026-06-01', 'nt_end' => '2026-06-20', 'nt_threshold' => '48'];
        $submitted = FulfillmentIssuePageLoader::load('notracking', 'scan_notracking', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['ntResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['ntError']);
        $this->assertSame(48, $submitted['ntThreshold']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testPostShipAddressChangeMissingShopifyCredentials(): void
    {
        $_POST = ['ps_start' => '2026-06-01', 'ps_end' => '2026-06-20'];

        $data = FulfillmentIssuePageLoader::load('postshipaddr', 'scan_postshipaddr', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['psResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['psError']);
        $this->assertSame('2026-06-01', $data['psStart']);
        $this->assertSame('2026-06-20', $data['psEnd']);
    }

    public function testSsShippedRequiresShipStationCredentialsFirst(): void
    {
        $_POST = ['ssu_start' => '2026-06-01', 'ssu_end' => '2026-06-20'];

        $data = FulfillmentIssuePageLoader::load('ssshipped', 'scan_ssshipped', $this->ctx(['ssKey' => '', 'ssSecret' => '']));

        $this->assertNull($data['ssuResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $data['ssuError']);
        $this->assertSame('2026-06-01', $data['ssuStart']);
        $this->assertSame('2026-06-20', $data['ssuEnd']);
    }

    public function testSlaBreachesCarriesThresholdAndMissingShopifyCredentials(): void
    {
        $_GET = ['sla_threshold' => '5'];
        $initial = FulfillmentIssuePageLoader::load('slabreaches', '', $this->ctx());

        $this->assertNull($initial['slaResult']);
        $this->assertSame('', $initial['slaError']);
        $this->assertSame(5, $initial['slaThreshold']);

        $_GET = [];
        $_POST = ['sla_start' => '2026-06-01', 'sla_end' => '2026-06-20', 'sla_threshold' => '7'];
        $submitted = FulfillmentIssuePageLoader::load('slabreaches', 'scan_sla', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['slaResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['slaError']);
        $this->assertSame(7, $submitted['slaThreshold']);
    }

    public function testShipmentAgingThresholdAndMissingShipStationCredentials(): void
    {
        $_GET = ['sa_threshold' => '8'];
        $initial = FulfillmentIssuePageLoader::load('shipmentaging', '', $this->ctx());

        $this->assertNull($initial['saResult']);
        $this->assertSame('', $initial['saError']);
        $this->assertSame(8, $initial['saThreshold']);
        $this->assertSame([], RunLog::all());

        $_GET = [];
        $_POST = ['sa_threshold' => '10'];
        $submitted = FulfillmentIssuePageLoader::load('shipmentaging', 'scan_shipmentaging', $this->ctx(['ssKey' => '', 'ssSecret' => '']));

        $this->assertNull($submitted['saResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $submitted['saError']);
        $this->assertSame(10, $submitted['saThreshold']);
        $this->assertSame('config_error', RunLog::all()[0]['status']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], FulfillmentIssuePageLoader::load('unknown', '', $this->ctx()));
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
