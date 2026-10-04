<?php

namespace App\Integrations\Shopify;

use InvalidArgumentException;

/**
 * Loads Admin API GraphQL documents from resources/graphql/shopify, one operation per file named after it.
 */
final class ShopifyQueries
{
    /** @var array<string, non-empty-string> */
    private static array $loaded = [];

    /**
     * @return non-empty-string
     */
    public static function get(string $operation): string
    {
        if (isset(self::$loaded[$operation])) {
            return self::$loaded[$operation];
        }

        if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $operation) !== 1) {
            throw new InvalidArgumentException('The Shopify GraphQL operation name is invalid.');
        }

        $path = self::path($operation);
        $document = is_file($path) ? rtrim((string) file_get_contents($path)) : '';

        if ($document === '') {
            throw new InvalidArgumentException("The Shopify GraphQL document [{$operation}] does not exist.");
        }

        return self::$loaded[$operation] = $document;
    }

    public static function path(string $operation): string
    {
        return resource_path("graphql/shopify/{$operation}.graphql");
    }
}
