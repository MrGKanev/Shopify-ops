<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Models\Store;

class ShopifyMutations
{
    public function __construct(private readonly ShopifyTransport $transport) {}

    /** @param array<string, mixed> $variables
     * @return array<string, mixed> */
    public function handle(Store $store, string $operation, string $field, array $variables): array
    {
        $response = $this->transport->graphql($store, ShopifyQueries::get($operation), $variables);
        $result = $response['data'][$field] ?? null;
        if (! is_array($result) || ! is_array($result['userErrors'] ?? null)) {
            throw new ShopifyGraphqlException([], 'Shopify returned an unexpected mutation response.');
        }
        $errors = [];
        foreach ($result['userErrors'] as $error) {
            if (! is_array($error)) {
                throw new ShopifyGraphqlException([], 'Shopify returned invalid mutation errors.');
            }
            $errors[] = $error;
        }
        if ($errors !== []) {
            throw new ShopifyGraphqlException($errors);
        }

        return $result;
    }
}
