<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ConfigValidator.php';

use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/configvalidator_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testValidOrderTypesPasses(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode([
            'fallback' => 'Other',
            'rules' => [
                ['name' => 'Pro', 'match' => 'sku_starts_with', 'value' => 'pro-'],
            ],
        ]));

        $result = ConfigValidator::validateOrderTypes($path);

        $this->assertTrue($result['ok']);
    }

    public function testInvalidOrderTypesReportsUnsupportedMatch(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode([
            'rules' => [
                ['name' => 'Bad', 'match' => 'unknown', 'value' => 'x'],
            ],
        ]));

        $result = ConfigValidator::validateOrderTypes($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not supported', implode("\n", $result['issues']));
    }

    public function testMissingTagPolicyIsOkWithNote(): void
    {
        $result = ConfigValidator::validateTagPolicy($this->tmpDir . '/tag_policy.json');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['present']);
        $this->assertNotEmpty($result['notes']);
    }
}
