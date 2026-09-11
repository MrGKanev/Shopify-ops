<?php

namespace App\Application\Auth;

use App\Models\LoginAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LoginThrottle
{
    private const int MAX_ATTEMPTS = 3;

    private const int ATTEMPT_WINDOW_SECONDS = 3600;

    private const int BAN_SECONDS = 604800;

    /** Message describing the remaining ban, or null if the IP isn't currently banned. */
    public function bannedMessage(string $ip): ?string
    {
        $entry = LoginAttempt::where('ip', $ip)->first();

        if ($entry === null || $entry->banned_until === null || $entry->banned_until->isPast()) {
            return null;
        }

        return $this->formatBanMessage($entry->banned_until);
    }

    /** Records a failed attempt and returns the message to show the user. */
    public function recordFailureMessage(string $ip): string
    {
        $attempts = DB::transaction(function () use ($ip): int {
            $now = Carbon::now()->startOfSecond();
            $entry = LoginAttempt::where('ip', $ip)->lockForUpdate()->first();

            if ($entry === null || $entry->first_attempt_at->lt($now->clone()->subSeconds(self::ATTEMPT_WINDOW_SECONDS))) {
                $entry = LoginAttempt::updateOrCreate(['ip' => $ip], ['attempts' => 0, 'first_attempt_at' => $now, 'banned_until' => null]);
            }

            $entry->attempts++;

            if ($entry->attempts >= self::MAX_ATTEMPTS) {
                $entry->banned_until = $now->clone()->addSeconds(self::BAN_SECONDS);
            }

            $entry->save();

            return $entry->attempts;
        });

        $remaining = max(0, self::MAX_ATTEMPTS - $attempts);

        if ($remaining > 0) {
            return 'Incorrect email or password. '.$remaining.' attempt'.($remaining !== 1 ? 's' : '').' remaining.';
        }

        return 'Too many failed attempts. Account locked for 1 week. Contact your administrator.';
    }

    public function recordSuccess(string $ip): void
    {
        LoginAttempt::where('ip', $ip)->delete();
    }

    /** @return Collection<int, LoginAttempt> currently banned IPs */
    public function bannedIps(): Collection
    {
        return LoginAttempt::whereNotNull('banned_until')->where('banned_until', '>', Carbon::now())->latest('banned_until')->get();
    }

    public function unban(string $ip): void
    {
        LoginAttempt::where('ip', $ip)->delete();
    }

    private function formatBanMessage(\Carbon\Carbon $bannedUntil): string
    {
        $seconds = max(0, Carbon::now()->startOfSecond()->diffInSeconds($bannedUntil, false));
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        if ($days > 0) {
            $message = "Too many failed attempts. Try again in {$days} day".($days !== 1 ? 's' : '');

            return $hours > 0 ? "{$message} and {$hours}h." : "{$message}.";
        }

        return "Too many failed attempts. Try again in {$hours} hour".($hours !== 1 ? 's' : '').'.';
    }
}
