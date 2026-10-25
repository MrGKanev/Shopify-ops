<?php

namespace App\Integrations\Exceptions;

class TrackingReadLimitExceeded extends IntegrationException
{
    public function userMessage(): string
    {
        return 'The store’s daily tracking-read limit has been reached (UTC).';
    }
}
