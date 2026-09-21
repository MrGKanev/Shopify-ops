<?php

namespace App\Integrations\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

trait RetriesTransientRequests
{
    /** @var list<int> */
    private const array RETRY_DELAYS_IN_MILLISECONDS = [100, 500, 1000];

    private function isTransientFailure(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->status() === 429 || $exception->response->serverError()));
    }
}
