<?php

namespace Tests\Unit\Application\Auth;

use App\Application\Auth\LoginThrottle;
use App\Models\LoginAttempt;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_is_not_banned_with_no_recorded_attempts(): void
    {
        $this->assertNull((new LoginThrottle)->bannedMessage('1.2.3.4'));
    }

    public function test_legacy_week_long_bans_no_longer_block_login(): void
    {
        LoginAttempt::create(['ip' => '1.2.3.4', 'attempts' => 3, 'first_attempt_at' => now()->subHour(), 'banned_until' => now()->addWeek()]);

        $this->assertNull((new LoginThrottle)->bannedMessage('1.2.3.4'));
        $this->assertCount(0, (new LoginThrottle)->bannedIps());
    }

    public function test_failed_credentials_receive_a_generic_message(): void
    {
        $throttle = new LoginThrottle;

        $this->assertSame('Incorrect email or password.', $throttle->recordFailureMessage('1.2.3.4'));
        $this->assertSame('Incorrect email or password.', $throttle->recordFailureMessage('1.2.3.4'));
    }

    public function test_ten_failures_block_the_ip_for_fifteen_minutes(): void
    {
        $throttle = new LoginThrottle;
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }
        $message = $throttle->recordFailureMessage('1.2.3.4');

        $this->assertSame('Too many failed attempts. Try again in 900 seconds.', $message);
        $entry = LoginAttempt::where('ip', '1.2.3.4')->firstOrFail();
        $this->assertTrue($entry->banned_until->isSameMinute(now()->addMinutes(15)));
    }

    public function test_banned_message_shows_seconds_remaining(): void
    {
        $this->travelTo(now());
        $throttle = new LoginThrottle;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }

        $this->assertSame('Too many failed attempts. Try again in 900 seconds.', $throttle->bannedMessage('1.2.3.4'));
    }

    public function test_banned_message_counts_down_seconds(): void
    {
        $this->travelTo(now());
        $throttle = new LoginThrottle;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }
        $this->travelTo(now()->addSeconds(30));

        $this->assertSame('Too many failed attempts. Try again in 870 seconds.', $throttle->bannedMessage('1.2.3.4'));
    }

    public function test_ban_expires_after_fifteen_minutes(): void
    {
        $throttle = new LoginThrottle;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }
        $this->travelTo(now()->addMinutes(15)->addSecond());

        $this->assertNull($throttle->bannedMessage('1.2.3.4'));
    }

    public function test_the_attempt_window_resets_after_a_minute_without_attempts(): void
    {
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $this->travelTo(now()->addMinute()->addSecond());

        $message = $throttle->recordFailureMessage('1.2.3.4');

        $this->assertSame('Incorrect email or password.', $message);
    }

    public function test_success_clears_the_recorded_attempts(): void
    {
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');

        $throttle->recordSuccess('1.2.3.4');

        $this->assertDatabaseMissing('login_attempts', ['ip' => '1.2.3.4']);
        $this->assertSame('Incorrect email or password.', $throttle->recordFailureMessage('1.2.3.4'));
    }

    public function test_different_ips_are_tracked_independently(): void
    {
        $throttle = new LoginThrottle;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }

        $this->assertNotNull($throttle->bannedMessage('1.2.3.4'));
        $this->assertNull($throttle->bannedMessage('5.6.7.8'));
    }

    public function test_banned_ips_lists_only_currently_banned_entries(): void
    {
        $throttle = new LoginThrottle;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }
        $throttle->recordFailureMessage('5.6.7.8');

        $banned = $throttle->bannedIps();

        $this->assertCount(1, $banned);
        $this->assertSame('1.2.3.4', $banned->first()->ip);
    }

    public function test_unban_removes_the_ban_immediately(): void
    {
        $throttle = new LoginThrottle;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }

        $throttle->unban('1.2.3.4');

        $this->assertNull($throttle->bannedMessage('1.2.3.4'));
        $this->assertCount(0, $throttle->bannedIps());
    }

    public function test_a_failure_during_an_active_ban_neither_resets_nor_extends_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        $throttle = new LoginThrottle;
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }
        $this->travelTo(now()->addSeconds(59));
        $throttle->recordFailureMessage('1.2.3.4');
        $bannedUntil = LoginAttempt::where('ip', '1.2.3.4')->firstOrFail()->banned_until;

        $this->travelTo(now()->addSeconds(5));
        $message = $throttle->recordFailureMessage('1.2.3.4');

        $entry = LoginAttempt::where('ip', '1.2.3.4')->firstOrFail();
        $this->assertSame(10, $entry->attempts);
        $this->assertTrue($entry->banned_until->equalTo($bannedUntil));
        $this->assertStringContainsString('Try again in', $message);
        $this->assertNotNull($throttle->bannedMessage('1.2.3.4'));
    }

    public function test_the_ninth_failure_does_not_ban_and_the_tenth_does(): void
    {
        $throttle = new LoginThrottle;
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }
        $this->assertNull($throttle->bannedMessage('1.2.3.4'));
        $this->assertNull(LoginAttempt::where('ip', '1.2.3.4')->firstOrFail()->banned_until);

        $throttle->recordFailureMessage('1.2.3.4');

        $this->assertNotNull($throttle->bannedMessage('1.2.3.4'));
    }
}
