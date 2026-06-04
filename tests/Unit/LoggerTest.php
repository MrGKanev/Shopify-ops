<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    private string $logDir;
    private string $logFile;

    protected function setUp(): void
    {
        $this->logDir  = sys_get_temp_dir() . '/logger_test_' . uniqid();
        $this->logFile = $this->logDir . '/app.log';
        Logger::resetInstance();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->logDir)) {
            rmdir($this->logDir);
        }
        Logger::resetInstance();
    }

    private function logger(): Logger
    {
        return Logger::getInstance($this->logDir);
    }

    public function testCreatesLogDirectory(): void
    {
        $this->logger()->info('hello');
        $this->assertDirectoryExists($this->logDir);
    }

    public function testWritesMessageToFile(): void
    {
        $this->logger()->info('test message');
        $this->assertStringContainsString('test message', file_get_contents($this->logFile));
    }

    public function testLevelAppearsInOutput(): void
    {
        $this->logger()->error('boom');
        $this->assertStringContainsString('ERROR', file_get_contents($this->logFile));
    }

    public function testInterpolatesContextPlaceholders(): void
    {
        $this->logger()->warning('order {id} failed', ['id' => '1234']);
        $this->assertStringContainsString('order 1234 failed', file_get_contents($this->logFile));
    }

    public function testMultipleEntriesAppended(): void
    {
        $log = $this->logger();
        $log->info('first');
        $log->info('second');
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('first',  $content);
        $this->assertStringContainsString('second', $content);
    }

    public function testRotatesWhenFileTooLarge(): void
    {
        $log = Logger::getInstance($this->logDir, maxBytes: 10);
        file_put_contents($this->logFile, str_repeat('x', 11));
        $log->info('after rotate');
        $backups = glob($this->logDir . '/*.bak') ?: [];
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('after rotate', file_get_contents($this->logFile));
    }

    public function testExceptionContextAppended(): void
    {
        $e = new \RuntimeException('something broke');
        $this->logger()->error('caught', ['exception' => $e->getMessage() . ' at line ' . $e->getLine()]);
        $this->assertStringContainsString('something broke', file_get_contents($this->logFile));
    }
}
