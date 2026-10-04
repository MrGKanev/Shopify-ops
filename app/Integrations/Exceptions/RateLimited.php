<?php

namespace App\Integrations\Exceptions;

class RateLimited extends IntegrationException
{
    public function __construct(public readonly ?int $retryAfter = null, ?int $status = 429)
    {
        parent::__construct($retryAfter === null ? 'The integration rate limit was reached. Try again later.' : "The integration rate limit was reached. Try again after {$retryAfter} seconds.", $status);
    }
}
