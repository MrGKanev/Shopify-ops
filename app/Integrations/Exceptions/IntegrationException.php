<?php

namespace App\Integrations\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class IntegrationException extends RuntimeException
{
    public function __construct(string $message = 'The integration request failed.', public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return match (true) {
            $this instanceof RateLimited => $this->retryAfter === null
                ? 'The integration rate limit was reached. Try again later.'
                : "The integration rate limit was reached. Try again after {$this->retryAfter} seconds.",
            $this instanceof Unauthorized => 'The integration credentials were rejected.',
            $this instanceof UnexpectedResponse => 'The integration returned an unexpected response. Try again later.',
            default => 'The integration request failed. Try again later.',
        };
    }

    public static function forResponse(Response $response): self
    {
        return match ($response->status()) {
            401, 403 => new Unauthorized('The integration credentials were rejected.', $response->status()),
            429 => new RateLimited(self::retryAfter($response), $response->status()),
            default => new UnexpectedResponse('The integration returned an unexpected HTTP response.', $response->status()),
        };
    }

    private static function retryAfter(Response $response): ?int
    {
        $value = trim($response->header('X-Rate-Limit-Reset'));
        if (! is_numeric($value)) {
            $value = trim($response->header('Retry-After'));
        }
        if (is_numeric($value)) {
            return max(1, (int) ceil((float) $value));
        }
        $timestamp = $value === '' ? false : strtotime($value);

        return $timestamp === false ? null : max(1, $timestamp - time());
    }
}
