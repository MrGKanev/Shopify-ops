<?php

namespace App\Application\Auth;

use App\Models\LoginAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LoginThrottle
{
    private const int MAX_ATTEMPTS = 100;

    private const int ATTEMPT_WINDOW_SECONDS = 60;

    private const int BAN_SECONDS = 60;

    /** Message describing the remaining ban, or null if the IP isn't currently banned. */
    public function bannedMessage(string $ip): ?string
    {
        $entry = LoginAttempt::where('ip', $ip)->first();

        $bannedUntil = $this->activeBanUntil($entry);

        if ($bannedUntil === null) {
            return null;
        }

        return $this->formatBanMessage($bannedUntil->min(now()->addSeconds(self::BAN_SECONDS)));
    }

    /** Records a failed attempt and returns the message to show the user. */
    public function recordFailureMessage(string $ip): string
    {
        $activeBan = null;
        $attempts = DB::transaction(function () use ($ip, &$activeBan): int {
            $now = Carbon::now()->startOfSecond();
            $entry = LoginAttempt::where('ip', $ip)->lockForUpdate()->first();

            $bannedUntil = $this->activeBanUntil($entry);

            if ($bannedUntil !== null) {
                $activeBan = $bannedUntil;

                return self::MAX_ATTEMPTS;
            }

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

        if ($activeBan !== null) {
            return $this->formatBanMessage($activeBan->min(now()->addSeconds(self::BAN_SECONDS)));
        }

        if ($attempts < self::MAX_ATTEMPTS) {
            return __('Incorrect email or password.');
        }

        return __('Too many failed attempts. Try again in :seconds seconds.', ['seconds' => self::BAN_SECONDS]);
    }

    public function recordSuccess(string $ip): void
    {
        LoginAttempt::where('ip', $ip)->delete();
    }

    /** @return Collection<int, LoginAttempt> currently banned IPs */
    public function bannedIps(): Collection
    {
        return LoginAttempt::whereNotNull('banned_until')->where('banned_until', '>', Carbon::now())
            ->where('first_attempt_at', '>=', now()->subSeconds(self::ATTEMPT_WINDOW_SECONDS + self::BAN_SECONDS))->latest('banned_until')->get();
    }

    public function unban(string $ip): void
    {
        LoginAttempt::where('ip', $ip)->delete();
    }

    /** A failure recorded while a ban runs must neither reset nor extend it. */
    private function activeBanUntil(?LoginAttempt $entry): ?\Carbon\Carbon
    {
        if ($entry === null || $entry->banned_until === null || ! $entry->banned_until->isFuture()) {
            return null;
        }

        if ($entry->first_attempt_at->lt(now()->subSeconds(self::ATTEMPT_WINDOW_SECONDS + self::BAN_SECONDS))) {
            return null;
        }

        return $entry->banned_until;
    }

    private function formatBanMessage(\Carbon\Carbon $bannedUntil): string
    {
        $seconds = (int) ceil(max(0, Carbon::now()->startOfSecond()->diffInSeconds($bannedUntil, false)));

        return __('Too many failed attempts. Try again in :seconds seconds.', ['seconds' => $seconds]);
    }
}
