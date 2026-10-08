<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Models\Store;
use UnexpectedValueException;

class ShopifyCustoms
{
    public function __construct(private readonly ShopifyTransport $transport) {}

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool, next_after: string|null, currency: string} */
    public function catalog(Store $store, ?string $after = null): array
    {
        $result = $this->transport->graphql($store, ShopifyQueries::get('CustomsCatalogProducts'), ['after' => $after]);
        $connection = $this->connection(data_get($result, 'data.products'), $after);
        $products = [];
        $pages = 1;
        $truncated = $connection['next'] !== null;
        foreach ($connection['nodes'] as $product) {
            if (! is_string($product['id'] ?? null) || preg_match('~^gid://shopify/Product/[0-9]+$~', $product['id']) !== 1) {
                throw new UnexpectedValueException('Shopify returned an invalid customs product.');
            }
            $variants = $this->nested($store, 'CustomsProductVariants', 'product', 'variants', $product['id']);
            $products[] = [...$product, 'customs_variants' => $variants['nodes'], 'customs_truncated' => $variants['truncated']];
            $pages += $variants['pages'];
            $truncated = $truncated || $variants['truncated'];
        }

        $currency = data_get($result, 'data.shop.currencyCode');

        return ['products' => $products, 'pages' => $pages, 'truncated' => $truncated, 'next_after' => $connection['next'], 'currency' => is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : ''];
    }

    /** @return array{order: array<string, mixed>, lines: list<array<string, mixed>>, truncated: bool} */
    public function order(Store $store, string $id): array
    {
        if (preg_match('~^gid://shopify/Order/[0-9]+$~', $id) !== 1) {
            throw new UnexpectedValueException('A valid Shopify order identity is required.');
        }
        $lines = $this->nested($store, 'CustomsOrderLines', 'order', 'lineItems', $id);

        return ['order' => $lines['resource'], 'lines' => $lines['nodes'], 'truncated' => $lines['truncated']];
    }

    /** @return array{order: array<string, mixed>, lines: list<array<string, mixed>>, truncated: bool} */
    public function productSyncOrder(Store $store, string $id): array
    {
        if (preg_match('~^gid://shopify/Order/[0-9]+$~', $id) !== 1) {
            throw new UnexpectedValueException('A valid Shopify order identity is required.');
        }
        $lines = $this->nested($store, 'ProductSyncOrderLines', 'order', 'lineItems', $id);

        return ['order' => $lines['resource'], 'lines' => $lines['nodes'], 'truncated' => $lines['truncated']];
    }

    /** @return array{resource: array<string, mixed>, nodes: list<array<string, mixed>>, pages: int, truncated: bool} */
    private function nested(Store $store, string $operation, string $root, string $field, string $id): array
    {
        $nodes = [];
        $seen = [];
        $after = null;
        $pages = 0;
        $resource = [];
        $version = null;
        do {
            $response = $this->transport->graphql($store, ShopifyQueries::get($operation), ['id' => $id, 'after' => $after]);
            $value = data_get($response, 'data.'.$root);
            if (! is_array($value) || ($value['id'] ?? null) !== $id || ! is_string($value['updatedAt'] ?? null)) {
                throw new UnexpectedValueException('Shopify could not confirm customs resource identity.');
            }
            if ($version !== null && $value['updatedAt'] !== $version) {
                throw new UnexpectedValueException('The customs source changed during pagination. Run the check again.');
            }
            $version = $value['updatedAt'];
            $resource = $value;
            unset($resource[$field]);
            $connection = $this->connection($value[$field] ?? null, $after);
            foreach ($connection['nodes'] as $node) {
                if (! is_string($node['id'] ?? null) || isset($seen[$node['id']])) {
                    throw new UnexpectedValueException('Shopify returned ambiguous customs items.');
                }
                $seen[$node['id']] = true;
                $nodes[] = $node;
            }
            $after = $connection['next'];
            $pages++;
        } while ($after !== null && $pages < 20);

        return ['resource' => $resource, 'nodes' => $nodes, 'pages' => $pages, 'truncated' => $after !== null];
    }

    /** @return array{nodes: list<array<string, mixed>>, next: string|null} */
    private function connection(mixed $connection, ?string $previous): array
    {
        if (! is_array($connection) || ! is_array($connection['nodes'] ?? null) || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
            throw new UnexpectedValueException('Shopify customs pagination was not confirmed.');
        }
        $nodes = [];
        foreach ($connection['nodes'] as $node) {
            if (! is_array($node)) {
                throw new UnexpectedValueException('Shopify returned an invalid customs item.');
            }
            $nodes[] = $node;
        }
        $next = $connection['pageInfo']['hasNextPage'] ? ($connection['pageInfo']['endCursor'] ?? null) : null;
        if ($connection['pageInfo']['hasNextPage'] && (! is_string($next) || $next === '' || $next === $previous)) {
            throw new UnexpectedValueException('Shopify customs pagination did not advance.');
        }

        return ['nodes' => $nodes, 'next' => $next];
    }
}
