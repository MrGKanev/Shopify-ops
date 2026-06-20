<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Shared Shopify Admin GraphQL ID helpers.
 */
class Ids
{
    public static function orderGid(string $orderId): string
    {
        $trimmed = trim($orderId);
        if (str_starts_with($trimmed, 'gid://shopify/Order/')) {
            return $trimmed;
        }

        if (!ctype_digit($trimmed)) {
            throw new \InvalidArgumentException("Unsupported Shopify order ID: {$orderId}");
        }

        return "gid://shopify/Order/{$trimmed}";
    }

    public static function legacyId(mixed $legacyResourceId, mixed $gid): int|string
    {
        $id = (string)($legacyResourceId ?? '');
        if ($id === '' && is_string($gid) && preg_match('~/(\d+)(?:\?.*)?$~', $gid, $matches)) {
            $id = $matches[1];
        }

        return ctype_digit($id) ? (int)$id : $id;
    }
}
