<?php

namespace App\Integrations;

use App\Integrations\Exceptions\RateLimited;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use SensitiveParameter;

class IntegrationThrottle
{
    public function shipStation(#[SensitiveParameter] string $apiKey): void
    {
        $key = 'integrations:ss:'.hash('sha256', $apiKey);
        $deadline = $this->seconds() + 30;
        do {
            $wait = $this->locked($key, function () use ($key): float {
                $now = $this->seconds();
                $timestamps = Cache::get($key, []);
                $timestamps = array_values(array_filter(is_array($timestamps) ? $timestamps : [], fn (mixed $timestamp): bool => is_numeric($timestamp) && $timestamp > $now - 60));
                if (count($timestamps) >= 38) {
                    return max(0.001, (float) $timestamps[0] + 60 - $now);
                }
                $timestamps[] = $now;
                Cache::put($key, $timestamps, 60);

                return 0.0;
            });
            if ($wait === 0.0) {
                return;
            }
            if ($this->seconds() + $wait > $deadline) {
                throw new RateLimited((int) ceil($wait));
            }
            Sleep::for($wait)->seconds();
        } while ($this->seconds() <= $deadline);

        throw new RateLimited(1);
    }

    /**
     * Serialize requests for one app/shop so a response cannot overwrite another worker's budget.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $request
     * @return TResult
     */
    public function shopify(string $shop, Closure $request): mixed
    {
        return $this->locked('integrations:shopify:'.hash('sha256', $shop), $request, 330);
    }

    public function waitForShopify(string $shop, string $query): bool
    {
        $budget = Cache::get($this->budgetKey($shop));
        if (! is_array($budget)) {
            return false;
        }
        $cost = Cache::get($this->budgetKey($shop).':'.hash('sha256', $query), $budget['cost']);
        $available = min($budget['maximum'], $budget['available'] + max(0, $this->seconds() - $budget['updated']) * $budget['rate']);
        $wait = 0.0;
        if ($cost > $available) {
            if ($budget['rate'] <= 0 || $cost > $budget['maximum']) {
                throw new RateLimited(60);
            }
            $wait = ($cost - $available) / $budget['rate'];
            if ($wait > 60) {
                throw new RateLimited((int) ceil($wait));
            }
            Sleep::for($wait)->seconds();
        }
        $budget['available'] = max(0, min($budget['maximum'], $available + $wait * $budget['rate']) - $cost);
        $budget['updated'] = $this->seconds();
        Cache::put($this->budgetKey($shop), $budget, 3600);

        return $wait > 0;
    }

    /** @param array<string, mixed> $response */
    public function observeShopify(string $shop, string $query, array $response): void
    {
        $cost = $response['extensions']['cost'] ?? null;
        $status = is_array($cost) ? ($cost['throttleStatus'] ?? null) : null;
        if (! is_array($status) || ! is_numeric($cost['requestedQueryCost'] ?? null)
            || ! is_numeric($status['currentlyAvailable'] ?? null) || ! is_numeric($status['restoreRate'] ?? null)
            || ! is_numeric($status['maximumAvailable'] ?? null)) {
            return;
        }
        Cache::put($this->budgetKey($shop), [
            'cost' => max(1, (float) $cost['requestedQueryCost']),
            'available' => max(0, (float) $status['currentlyAvailable']),
            'rate' => max(0, (float) $status['restoreRate']),
            'maximum' => max(0, (float) $status['maximumAvailable']),
            'updated' => $this->seconds(),
        ], 3600);
        Cache::put($this->budgetKey($shop).':'.hash('sha256', $query), max(1, (float) $cost['requestedQueryCost']), 3600);
    }

    private function budgetKey(string $shop): string
    {
        return 'integrations:shopify-budget:'.hash('sha256', $shop);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function locked(string $key, Closure $callback, int $ttl = 5): mixed
    {
        try {
            return Cache::lock($key.':lock', $ttl)->block(30, $callback);
        } catch (LockTimeoutException) {
            throw new RateLimited(30);
        }
    }

    private function seconds(): float
    {
        return (float) now()->format('U.u');
    }
}
