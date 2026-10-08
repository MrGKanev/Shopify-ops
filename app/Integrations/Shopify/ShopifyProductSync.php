<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Models\Store;
use UnexpectedValueException;

class ShopifyProductSync
{
    public function __construct(private readonly ShopifyTransport $transport) {}

    /** @return array{variants: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function catalog(Store $store): array
    {
        $variants = [];
        $seen = [];
        $cursors = [];
        $after = null;
        $pages = 0;
        do {
            $response = $this->transport->graphql($store, ShopifyQueries::get('ProductSyncVariants'), ['after' => $after]);
            $data = data_get($response, 'data.productVariants');
            if (! is_array($data) || ! is_array($data['nodes'] ?? null) || ! is_bool($data['pageInfo']['hasNextPage'] ?? null)) {
                throw new UnexpectedValueException('Shopify product sync pagination was not confirmed.');
            }
            foreach ($data['nodes'] as $variant) {
                if (! is_array($variant) || ! is_string($variant['id'] ?? null) || preg_match('~^gid://shopify/ProductVariant/[0-9]+$~', $variant['id']) !== 1 || isset($seen[$variant['id']])) {
                    throw new UnexpectedValueException('Shopify returned ambiguous product sync variants.');
                }
                $seen[$variant['id']] = true;
                $variants[] = $variant;
            }
            $next = $data['pageInfo']['hasNextPage'] ? ($data['pageInfo']['endCursor'] ?? null) : null;
            if ($data['pageInfo']['hasNextPage'] && (! is_string($next) || $next === '' || isset($cursors[$next]))) {
                throw new UnexpectedValueException('Shopify product sync pagination did not advance.');
            }
            if ($next !== null) {
                $cursors[$next] = true;
            }
            $after = $next;
            $pages++;
        } while ($after !== null && $pages < 20);

        return ['variants' => $variants, 'pages' => $pages, 'truncated' => $after !== null];
    }

    /** @param array<string, mixed> $variant
     * @return array<string, mixed> */
    public function withBundle(Store $store, array $variant): array
    {
        if (($variant['requiresComponents'] ?? null) !== true) {
            return $variant;
        }
        $data = $this->transport->graphql($store, ShopifyQueries::get('ProductSyncBundle'), ['id' => $variant['id']]);
        $bundle = data_get($data, 'data.productVariant');
        if (! is_array($bundle) || ($bundle['id'] ?? null) !== $variant['id'] || ($bundle['updatedAt'] ?? null) !== ($variant['updatedAt'] ?? null)) {
            throw new UnexpectedValueException('Shopify bundle changed during the check.');
        }

        return [...$variant, 'productVariantComponents' => $bundle['productVariantComponents'] ?? null];
    }
}
