<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/SlackRules.php';

use PHPUnit\Framework\TestCase;

class SlackRulesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/slackrules_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        SlackRules::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testDefaultsNotifyAuditAllClear(): void
    {
        $this->assertTrue(SlackRules::shouldNotifyAudit(0));
    }

    public function testAuditThreshold(): void
    {
        SlackRules::save(['audit_enabled' => true, 'audit_min_missing' => 2, 'include_zero_audit' => false]);

        $this->assertFalse(SlackRules::shouldNotifyAudit(0));
        $this->assertFalse(SlackRules::shouldNotifyAudit(1));
        $this->assertTrue(SlackRules::shouldNotifyAudit(2));
    }

    public function testScanNotificationsDefaultOff(): void
    {
        $this->assertFalse(SlackRules::shouldNotifyScan(10));
    }
}
