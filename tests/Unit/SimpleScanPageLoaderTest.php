<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/SlackRules.php';
require_once __DIR__ . '/../../src/SlackNotifier.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ScanRunner.php';
require_once __DIR__ . '/../../src/SimpleScanPageLoader.php';

use PHPUnit\Framework\TestCase;

class SimpleScanPageLoaderTest extends TestCase
{
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private string|false $previousSlackWebhook;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/simple_scan_loader_' . uniqid();
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

    public function testTagAuditInitialStateUsesRequestRange(): void
    {
        $_GET = ['ta_start' => '2026-06-01', 'ta_end' => '2026-06-20'];

        $data = SimpleScanPageLoader::load('tagaudit', '', $this->ctx());

        $this->assertNull($data['tagAuditResult']);
        $this->assertSame('', $data['tagAuditError']);
        $this->assertSame('2026-06-01', $data['taStart']);
        $this->assertSame('2026-06-20', $data['taEnd']);
        $this->assertSame([], RunLog::all());
    }

    public function testTagAuditMissingShopifyCredentials(): void
    {
        $_POST = ['ta_start' => '2026-06-01', 'ta_end' => '2026-06-20'];

        $data = SimpleScanPageLoader::load('tagaudit', 'tag_audit', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['tagAuditResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['tagAuditError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testEmailCheckMissingShopifyCredentials(): void
    {
        $_POST = ['email_start' => '2026-06-01', 'email_end' => '2026-06-20'];

        $data = SimpleScanPageLoader::load('emailcheck', 'scan_emails', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['emailResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['emailError']);
        $this->assertSame('2026-06-01', $data['emailStart']);
        $this->assertSame('2026-06-20', $data['emailEnd']);
    }

    public function testHighValueOrdersCarriesMinimumAndMissingCredentials(): void
    {
        $_GET = ['hv_min' => '350'];
        $initial = SimpleScanPageLoader::load('hvorders', '', $this->ctx());

        $this->assertNull($initial['hvResult']);
        $this->assertSame('', $initial['hvError']);
        $this->assertSame(350, $initial['hvMin']);

        $_GET = [];
        $_POST = ['hv_start' => '2026-06-01', 'hv_end' => '2026-06-20', 'hv_min' => '500'];
        $submitted = SimpleScanPageLoader::load('hvorders', 'scan_hvorders', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['hvResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['hvError']);
        $this->assertSame(500, $submitted['hvMin']);
    }

    public function testCountryMismatchMissingShopifyCredentials(): void
    {
        $_POST = ['cm_start' => '2026-06-01', 'cm_end' => '2026-06-20'];

        $data = SimpleScanPageLoader::load('countrymismatch', 'scan_country_mismatch', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['cmResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['cmError']);
        $this->assertSame('2026-06-01', $data['cmStart']);
        $this->assertSame('2026-06-20', $data['cmEnd']);
    }

    public function testPartialFulfillCarriesThresholdAndMissingCredentials(): void
    {
        $_GET = ['pf_threshold' => '12'];
        $initial = SimpleScanPageLoader::load('partialfulfill', '', $this->ctx());

        $this->assertNull($initial['pfResult']);
        $this->assertSame('', $initial['pfError']);
        $this->assertSame(12, $initial['pfThreshold']);

        $_GET = [];
        $_POST = ['pf_start' => '2026-06-01', 'pf_end' => '2026-06-20', 'pf_threshold' => '9'];
        $submitted = SimpleScanPageLoader::load('partialfulfill', 'scan_partial_fulfill', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['pfResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['pfError']);
        $this->assertSame(9, $submitted['pfThreshold']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], SimpleScanPageLoader::load('unknown', '', $this->ctx()));
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'shopifyToken' => 'tok_test',
            'shopifyStore' => 'test.myshopify.com',
            'ssKey'        => 'ss_key',
            'ssSecret'     => 'ss_secret',
        ];
    }
}
