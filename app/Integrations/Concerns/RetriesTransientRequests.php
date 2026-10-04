<?php

namespace App\Integrations\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

trait RetriesTransientRequests
{
    /** @var list<int> */
    private const array RETRY_DELAYS_IN_MILLISECONDS = [100, 500, 1000];

    /** Longest wait honoured from a rate-limit response; ShipStation's window is one minute. */
    private const int MAX_RATE_LIMIT_WAIT_SECONDS = 60;

    private function isTransientFailure(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->status() === 429 || $exception->response->serverError()));
    }

    /**
     * Total attempts: the first request plus one retry per configured delay.
     */
    private function retryAttempts(): int
    {
        return count(self::RETRY_DELAYS_IN_MILLISECONDS) + 1;
    }

    /**
     * Wait as long as a 429 response asks (X-Rate-Limit-Reset, then Retry-After); otherwise use the short backoff.
     */
    private function retryDelayInMilliseconds(int $attempt, mixed $exception): int
    {
        $backoff = self::RETRY_DELAYS_IN_MILLISECONDS[$attempt - 1] ?? self::RETRY_DELAYS_IN_MILLISECONDS[array_key_last(self::RETRY_DELAYS_IN_MILLISECONDS)];

        if (! $exception instanceof RequestException || $exception->response->status() !== 429) {
            return $backoff;
        }

        $seconds = $this->rateLimitWaitSeconds($exception->response->header('X-Rate-Limit-Reset'))
            ?? $this->rateLimitWaitSeconds($exception->response->header('Retry-After'));

        return $seconds === null ? $backoff : $seconds * 1000;
    }

    private function rateLimitWaitSeconds(string $header): ?int
    {
        $header = trim($header);

        return is_numeric($header) ? (int) min(self::MAX_RATE_LIMIT_WAIT_SECONDS, max(1, ceil((float) $header))) : null;
    }
}
