<?php

namespace App\Integrations\Shopify;

use App\Integrations\Concerns\ConfiguresIntegrationRequests;
use App\Integrations\Concerns\RetriesTransientRequests;
use App\Integrations\Exceptions\RateLimited;
use App\Integrations\IntegrationThrottle;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\Exceptions\ShopifyResponseException;
use App\Models\Store;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

/**
 * HTTP transport for the versioned Shopify Admin API: GraphQL requests, cursor pagination and REST reads.
 *
 * Every GraphQL call goes through graphql(), which is the single place to add cost-based throttling.
 */
class ShopifyGraphqlTransport
{
    use ConfiguresIntegrationRequests;
    use RetriesTransientRequests;

    public const API_VERSION = '2026-07';

    private string $lastResponseApiVersion = '';

    /**
     * The X-Shopify-API-Version header of the most recent GraphQL response.
     */
    public function lastResponseApiVersion(): string
    {
        return $this->lastResponseApiVersion;
    }

    /**
     * @param  array<string, bool|int|string>  $query
     * @return array<string, mixed>
     */
    public function get(Store $store, string $resource, array $query = []): array
    {
        $response = $this->request($store)
            ->retry(
                $this->retryAttempts(),
                fn (int $attempt, mixed $exception): int => $this->retryDelayInMilliseconds($attempt, $exception),
                when: fn (Throwable $exception): bool => $this->isTransientFailure($exception),
                throw: false,
            )
            ->get($this->resourcePath($resource), $query);
        $this->checkedResponse($response);

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ShopifyResponseException('Shopify returned an invalid JSON response.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(Store $store, string $query, array $variables = []): array
    {
        return $this->graphqlWithThrottle($store, $query, $variables);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphqlWithThrottle(Store $store, string $query, array $variables): array
    {
        $throttle = app(IntegrationThrottle::class);
        for ($attempt = 1; $attempt <= $this->retryAttempts(); $attempt++) {
            $result = $throttle->shopify((string) $store->shopify_store, fn (): array => $this->throttledGraphqlRequest($store, $query, $variables, $attempt));
            $errors = $result['errors'] ?? [];
            if ($errors !== [] && is_array($errors)
                && ! isset($result['data'])
                && count(array_filter($errors, fn (mixed $error): bool => is_array($error) && ($error['extensions']['code'] ?? null) === 'THROTTLED')) === count($errors)) {
                if ($attempt === $this->retryAttempts()) {
                    throw new RateLimited(1, 200);
                }

                continue;
            }
            if ($errors !== []) {
                throw new ShopifyGraphqlException($errors);
            }
            if (! array_key_exists('data', $result)) {
                throw new ShopifyGraphqlException([], 'Shopify GraphQL returned an unexpected response shape.');
            }

            return $result;
        }
        throw new RateLimited(1, 200);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function throttledGraphqlRequest(Store $store, string $query, array $variables, int $attempt): array
    {
        $throttle = app(IntegrationThrottle::class);
        $waited = $throttle->waitForShopify((string) $store->shopify_store, $query);
        if ($attempt > 1 && ! $waited) {
            Sleep::for(100)->milliseconds();
        }
        $result = $this->graphqlRequest($store, $query, $variables);
        $throttle->observeShopify((string) $store->shopify_store, $query, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphqlRequest(Store $store, string $query, array $variables): array
    {
        $payload = ['query' => $query];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $this->lastResponseApiVersion = '';
        $response = $this->request($store)
            ->when(! str_contains($query, 'mutation'), fn (PendingRequest $request): PendingRequest => $request->retry(
                $this->retryAttempts(),
                fn (int $attempt, mixed $exception): int => $this->retryDelayInMilliseconds($attempt, $exception),
                when: fn (Throwable $exception): bool => $this->isTransientFailure($exception),
                throw: false,
            ))
            ->post('graphql.json', $payload);
        $this->checkedResponse($response);
        $this->lastResponseApiVersion = trim($response->header('X-Shopify-API-Version'));

        $result = json_decode($response->body(), true);

        if (! is_array($result)) {
            throw new ShopifyGraphqlException([], 'Shopify GraphQL returned an unexpected response shape.');
        }

        if (array_key_exists('errors', $result) && ! is_array($result['errors'])) {
            throw new ShopifyGraphqlException([], 'Shopify GraphQL returned an unexpected response shape.');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{edges: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    public function paginateGraphql(
        Store $store,
        string $query,
        string $rootKey,
        array $variables = [],
        int $maxPages = 20,
    ): array {
        if ($maxPages < 1) {
            throw new InvalidArgumentException('Shopify pagination requires at least one page.');
        }

        $edges = [];
        $cursor = null;
        $pages = 0;
        $hasNextPage = false;

        do {
            $result = $this->graphql($store, $query, [
                ...$variables,
                'after' => $cursor,
            ]);
            $connection = $result['data'][$rootKey] ?? null;

            if (! is_array($connection)
                || ! is_array($connection['edges'] ?? null)
                || ! is_array($connection['pageInfo'] ?? null)
                || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify pagination returned an unexpected response shape.');
            }

            foreach ($connection['edges'] as $edge) {
                if (! is_array($edge)) {
                    throw new ShopifyGraphqlException([], 'Shopify pagination returned an unexpected response shape.');
                }

                $edges[] = $edge;
            }

            $pageInfo = $connection['pageInfo'];
            $hasNextPage = $pageInfo['hasNextPage'];
            $cursor = is_string($pageInfo['endCursor'] ?? null)
                ? $pageInfo['endCursor']
                : null;

            if ($hasNextPage && $cursor === null) {
                throw new ShopifyGraphqlException([], 'Shopify pagination returned an unexpected response shape.');
            }

            $pages++;
        } while ($hasNextPage && $pages < $maxPages);

        return [
            'edges' => $edges,
            'pages' => $pages,
            'truncated' => $hasNextPage,
        ];
    }

    private function request(Store $store): PendingRequest
    {
        $accessToken = (string) $store->shopify_access_token;

        if ($accessToken === '') {
            throw new InvalidArgumentException('The Shopify access token is missing.');
        }

        Context::add('store_id', $store->getKey());

        return $this->integrationRequest($this->baseUrl($store))
            ->withHeader('X-Shopify-Access-Token', $accessToken);
    }

    private function baseUrl(Store $store): string
    {
        $shop = (string) $store->shopify_store;

        if (preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $shop) !== 1) {
            throw new InvalidArgumentException('The Shopify store identifier is invalid.');
        }

        return "https://{$shop}.myshopify.com/admin/api/".self::API_VERSION;
    }

    private function resourcePath(string $resource): string
    {
        if ($resource === ''
            || str_starts_with($resource, '/')
            || str_contains($resource, '..')
            || str_contains($resource, '://')
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/-]*\z/', $resource) !== 1) {
            throw new InvalidArgumentException('The Shopify resource must be a relative path.');
        }

        return $resource;
    }
}
