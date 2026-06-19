<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/UserActionLog.php';

use PHPUnit\Framework\TestCase;

class UserActionLogTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/actionlog_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        UserActionLog::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testAppendStoresNewestFirst(): void
    {
        UserActionLog::append('ignore_order', ['order_number' => '1001']);
        UserActionLog::append('unignore_order', ['order_number' => '1001']);

        $rows = UserActionLog::all();

        $this->assertSame('unignore_order', $rows[0]['action']);
        $this->assertSame('ignore_order', $rows[1]['action']);
        $this->assertSame('1001', $rows[0]['details']['order_number']);
    }
}
