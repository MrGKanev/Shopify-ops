<?php

namespace App\Integrations\Concerns;

use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

trait ConfiguresIntegrationRequests
{
    private function integrationRequest(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withUserAgent('ShopifyOps/2.0')
            ->connectTimeout(3)
            ->timeout(15);
    }

    private function checkedResponse(Response $response): Response
    {
        if ($response->failed()) {
            throw IntegrationException::forResponse($response);
        }

        return $response;
    }
}
