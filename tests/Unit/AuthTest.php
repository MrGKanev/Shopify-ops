<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    public function testVerifyPasswordSupportsPlainTextCompatibility(): void
    {
        $this->assertTrue(Auth::verifyPassword('secret', 'secret'));
        $this->assertFalse(Auth::verifyPassword('wrong', 'secret'));
    }

    public function testVerifyPasswordSupportsPasswordHash(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);

        $this->assertTrue(Auth::verifyPassword('secret', $hash));
        $this->assertFalse(Auth::verifyPassword('wrong', $hash));
    }
}
