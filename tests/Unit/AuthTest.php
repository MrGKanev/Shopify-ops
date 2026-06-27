<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/auth_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        Auth::setDataDir($this->tmpDir);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/login_attempts.json';
        if (file_exists($file)) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        $_SESSION = [];
    }

    // ── verifyPassword ────────────────────────────────────────────────────────

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

    // ── attempt ───────────────────────────────────────────────────────────────

    public function testAttemptReturnsEmptyStringOnSuccess(): void
    {
        $result = Auth::attempt('admin', 'secret', 'admin', 'secret', '127.0.0.1');
        $this->assertSame('', $result);
    }

    public function testAttemptReturnsErrorMessageOnWrongPassword(): void
    {
        $result = Auth::attempt('admin', 'wrong', 'admin', 'secret', '127.0.0.1');
        $this->assertNotSame('', $result);
        $this->assertStringContainsString('Incorrect', $result);
    }

    public function testAttemptReturnsErrorMessageOnWrongUsername(): void
    {
        $result = Auth::attempt('baduser', 'secret', 'admin', 'secret', '127.0.0.1');
        $this->assertStringContainsString('Incorrect', $result);
    }

    public function testAttemptShowsDecreasingRemainingCountOnEachFailure(): void
    {
        $ip = '10.0.0.1';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        $result = Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        $this->assertStringContainsString('1 attempt remaining', $result);
    }

    public function testAttemptLocksOutIpAfterMaxFailedAttempts(): void
    {
        $ip = '10.0.0.2';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        $result = Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        $this->assertStringContainsString('Too many', $result);
    }

    public function testAttemptLockedOutMessageMentionsTimeRemaining(): void
    {
        $ip = '10.0.0.3';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        $result = Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        $this->assertStringContainsString('day', $result);
    }

    public function testAttemptSuccessClearsFailedAttemptsForIp(): void
    {
        $ip = '10.0.0.4';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'secret', 'admin', 'secret', $ip);

        $this->assertArrayNotHasKey($ip, Auth::bannedIps());
    }

    // ── bannedIps ─────────────────────────────────────────────────────────────

    public function testBannedIpsReturnsEmptyArrayWhenNoFileExists(): void
    {
        $this->assertSame([], Auth::bannedIps());
    }

    public function testBannedIpsReturnsIpAfterLockout(): void
    {
        $ip = '192.168.1.1';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        $banned = Auth::bannedIps();
        $this->assertArrayHasKey($ip, $banned);
    }

    public function testBannedIpsDoesNotIncludeNonLockedIps(): void
    {
        $ip = '192.168.1.3';
        // Only one failed attempt — not yet locked
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        $this->assertArrayNotHasKey($ip, Auth::bannedIps());
    }

    // ── unban ─────────────────────────────────────────────────────────────────

    public function testUnbanRemovesBannedIpFromList(): void
    {
        $ip = '192.168.1.2';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        Auth::unban($ip);

        $this->assertArrayNotHasKey($ip, Auth::bannedIps());
    }

    public function testUnbanDoesNothingWhenNoFileExists(): void
    {
        Auth::unban('1.2.3.4');
        $this->assertSame([], Auth::bannedIps());
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    public function testCsrfTokenCreatesNonEmptyTokenWhenSessionIsEmpty(): void
    {
        $token = Auth::csrfToken();
        $this->assertNotEmpty($token);
    }

    public function testCsrfTokenReturnsSameTokenOnRepeatedCalls(): void
    {
        $first  = Auth::csrfToken();
        $second = Auth::csrfToken();
        $this->assertSame($first, $second);
    }

    public function testCsrfTokenUsesExistingSessionValue(): void
    {
        $_SESSION['_csrf'] = 'preset-token';
        $this->assertSame('preset-token', Auth::csrfToken());
    }

    public function testRotateCsrfTokenReturnsNewToken(): void
    {
        $original = Auth::csrfToken();
        $rotated  = Auth::rotateCsrfToken();
        $this->assertNotSame($original, $rotated);
    }

    public function testRotateCsrfTokenUpdatesSessionValue(): void
    {
        $rotated = Auth::rotateCsrfToken();
        $this->assertSame($rotated, $_SESSION['_csrf']);
    }

    public function testValidateCsrfReturnsTrueForMatchingToken(): void
    {
        $token = Auth::csrfToken();
        $this->assertTrue(Auth::validateCsrf($token));
    }

    public function testValidateCsrfReturnsFalseForWrongToken(): void
    {
        Auth::csrfToken();
        $this->assertFalse(Auth::validateCsrf('wrong-token'));
    }

    public function testValidateCsrfReturnsFalseWhenNoSessionTokenSet(): void
    {
        $this->assertFalse(Auth::validateCsrf('any-token'));
    }
}
