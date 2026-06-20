<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Maps Shopify Admin GraphQL metafields into the legacy REST metafield shape.
 */
class MetafieldNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public static function normalizeMetafield(array $metafield, string $ownerId): array
    {
        return [
            'id'                   => Ids::legacyId(null, $metafield['id'] ?? null),
            'namespace'            => $metafield['namespace'] ?? '',
            'key'                  => $metafield['key'] ?? '',
            'value'                => $metafield['value'] ?? '',
            'type'                 => $metafield['type'] ?? '',
            'owner_id'             => Ids::legacyId(null, Ids::orderGid($ownerId)),
            'owner_resource'       => 'order',
            'created_at'           => $metafield['createdAt'] ?? '',
            'updated_at'           => $metafield['updatedAt'] ?? '',
            'admin_graphql_api_id' => $metafield['id'] ?? '',
        ];
    }
}
