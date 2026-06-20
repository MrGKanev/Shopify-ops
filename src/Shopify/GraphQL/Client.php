<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;

/**
 * Thin Admin GraphQL transport wrapper for Shopify.
 */
class Client
{
    private readonly HttpClient $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        ?HandlerStack $stack = null
    ) {
        $stack ??= HandlerStack::create();
        $stack->push(Middleware::retry(
            function (int $retries, $req, ?ResponseInterface $res = null) {
                if ($res?->getStatusCode() !== 429 || $retries >= 5) return false;
                $h    = $res->getHeaderLine('Retry-After');
                $wait = $h !== '' ? (int)$h : 10;
                $this->logWarning('Shopify GraphQL rate limited; retrying after {seconds}s', ['seconds' => $wait]);
                return true;
            },
            function ($retries, $res) {
                $h = $res?->getHeaderLine('Retry-After') ?? '';
                return ($h !== '' ? (int)$h : 10) * 1000;
            }
        ));
        $this->http = new HttpClient(['handler' => $stack]);
    }

    /**
     * Executes a GraphQL query against the Shopify Admin API.
     *
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $response = $this->request('POST', $this->baseUrl . '/graphql.json', [
            'json' => $payload,
        ]);

        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("Shopify GraphQL error {$code}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (isset($decoded['errors'])) {
            throw new \RuntimeException("Shopify GraphQL: " . json_encode($decoded['errors']));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Runs a paginated GraphQL query, calling $processor with each page's edges.
     * The query template must contain {{AFTER}} where the cursor argument goes
     * (e.g. `orders(first: 250, query: "..."{{AFTER}}) {`).
     *
     * @param  callable(array $edges): void $processor
     * @return array{truncated: bool, pages: int}
     */
    public function paginateGraphQL(
        string   $queryTemplate,
        string   $rootKey,
        callable $processor,
        int      $maxPages = 20
    ): array {
        $cursor  = null;
        $page    = 0;
        $hasNext = false;

        do {
            $after = $cursor ? ", after: \"{$cursor}\"" : '';
            $gql   = str_replace('{{AFTER}}', $after, $queryTemplate);

            $data  = $this->graphql($gql);
            $conn  = $data['data'][$rootKey] ?? [];
            $edges = $conn['edges'] ?? [];

            $processor($edges);

            $hasNext = $conn['pageInfo']['hasNextPage'] ?? false;
            $cursor  = $conn['pageInfo']['endCursor']   ?? null;
            $page++;
        } while ($hasNext && $cursor && $page < $maxPages);

        return ['truncated' => $hasNext, 'pages' => $page];
    }

    /**
     * Runs a paginated GraphQL query that uses an `$after` variable.
     *
     * @param  callable(array $edges): void $processor
     * @return array{truncated: bool, pages: int}
     */
    public function paginateGraphQLVariables(
        string   $query,
        string   $rootKey,
        array    $variables,
        callable $processor,
        int      $maxPages = 20
    ): array {
        $cursor  = null;
        $page    = 0;
        $hasNext = false;

        do {
            $data  = $this->graphql($query, $variables + ['after' => $cursor]);
            $conn  = $data['data'][$rootKey] ?? [];
            $edges = $conn['edges'] ?? [];

            $processor($edges);

            $hasNext = $conn['pageInfo']['hasNextPage'] ?? false;
            $cursor  = $conn['pageInfo']['endCursor']   ?? null;
            $page++;
        } while ($hasNext && $cursor && $page < $maxPages);

        return ['truncated' => $hasNext, 'pages' => $page];
    }

    /**
     * Sends a request with auth headers and handles 429 retry automatically.
     * Pass 'json' in $options for POST bodies (Guzzle encodes + sets Content-Type).
     */
    private function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['http_errors']                        = false;
        $options['timeout']                            = $options['timeout'] ?? 30;
        $options['headers']['X-Shopify-Access-Token']  = $this->token;
        $options['headers']['Content-Type']           ??= 'application/json';

        return $this->http->request($method, $url, $options);
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (class_exists(\Logger::class)) {
            \Logger::getInstance()->warning($message, $context);
        }
    }
}
