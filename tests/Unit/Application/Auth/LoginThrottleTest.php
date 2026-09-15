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

    public function test_first_two_failures_count_down_remaining_attempts(): void
    {
        $throttle = new LoginThrottle;

        $this->assertSame('Incorrect email or password. 2 attempts remaining.', $throttle->recordFailureMessage('1.2.3.4'));
        $this->assertSame('Incorrect email or password. 1 attempt remaining.', $throttle->recordFailureMessage('1.2.3.4'));
    }

    public function test_the_third_failure_bans_the_ip_for_a_week(): void
    {
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');

        $message = $throttle->recordFailureMessage('1.2.3.4');

        $this->assertSame('Too many failed attempts. Account locked for 1 week. Contact your administrator.', $message);
        $entry = LoginAttempt::where('ip', '1.2.3.4')->firstOrFail();
        $this->assertTrue($entry->banned_until->isSameMinute(now()->addWeek()));
    }

    public function test_banned_message_shows_days_remaining(): void
    {
        $this->travelTo(now());
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');

        $this->assertSame('Too many failed attempts. Try again in 7 days.', $throttle->bannedMessage('1.2.3.4'));
    }

    public function test_banned_message_shows_hours_when_under_a_day_remains(): void
    {
        $this->travelTo(now());
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $this->travelTo(now()->addDays(6)->addHours(23));

        $this->assertSame('Too many failed attempts. Try again in 1 hour.', $throttle->bannedMessage('1.2.3.4'));
    }

    public function test_ban_expires_after_a_week_and_stops_blocking(): void
    {
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $this->travelTo(now()->addWeek()->addSecond());

        $this->assertNull($throttle->bannedMessage('1.2.3.4'));
    }

    public function test_the_attempt_window_resets_after_an_hour_of_no_attempts(): void
    {
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $this->travelTo(now()->addHour()->addSecond());

        $message = $throttle->recordFailureMessage('1.2.3.4');

        $this->assertSame('Incorrect email or password. 2 attempts remaining.', $message);
    }

    public function test_success_clears_the_recorded_attempts(): void
    {
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');

        $throttle->recordSuccess('1.2.3.4');

        $this->assertDatabaseMissing('login_attempts', ['ip' => '1.2.3.4']);
        $this->assertSame('Incorrect email or password. 2 attempts remaining.', $throttle->recordFailureMessage('1.2.3.4'));
    }

    public function test_different_ips_are_tracked_independently(): void
    {
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');

        $this->assertNotNull($throttle->bannedMessage('1.2.3.4'));
        $this->assertNull($throttle->bannedMessage('5.6.7.8'));
    }

    public function test_banned_ips_lists_only_currently_banned_entries(): void
    {
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('5.6.7.8');

        $banned = $throttle->bannedIps();

        $this->assertCount(1, $banned);
        $this->assertSame('1.2.3.4', $banned->first()->ip);
    }

    public function test_unban_removes_the_ban_immediately(): void
    {
        $throttle = new LoginThrottle;
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');

        $throttle->unban('1.2.3.4');

        $this->assertNull($throttle->bannedMessage('1.2.3.4'));
        $this->assertCount(0, $throttle->bannedIps());
    }
}
