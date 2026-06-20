<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Maps Shopify Admin GraphQL event payloads into the legacy REST event shape.
 */
class EventNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public static function normalizeEvent(array $event, ?string $fallbackOrderId = null): array
    {
        $subjectGid = (string)($event['subjectId'] ?? '');
        if ($subjectGid === '' && $fallbackOrderId !== null) {
            $subjectGid = Ids::orderGid($fallbackOrderId);
        }

        $subjectId = $subjectGid !== '' ? Ids::legacyId(null, $subjectGid) : '';
        $action    = strtolower((string)($event['action'] ?? ''));

        return [
            'id'                   => Ids::legacyId(null, $event['id'] ?? null),
            'admin_graphql_api_id' => $event['id'] ?? '',
            'verb'                 => $action,
            'action'               => $action,
            'created_at'           => $event['createdAt'] ?? '',
            'message'              => (string)($event['message'] ?? ''),
            'subject_id'           => $subjectId,
            'subject_type'         => strtolower((string)($event['subjectType'] ?? 'Order')),
            'subject_graphql_api_id' => $subjectGid,
            'app_title'            => $event['appTitle'] ?? '',
        ];
    }

    public static function isAddressChangeEvent(array $event): bool
    {
        $haystack = strtolower(trim(
            (string)($event['verb'] ?? '') . ' ' .
            (string)($event['action'] ?? '') . ' ' .
            (string)($event['message'] ?? '')
        ));

        return str_contains($haystack, 'shipping address')
            || str_contains($haystack, 'address was')
            || str_contains($haystack, 'shipping_address');
    }

    public static function isOrderEditEvent(array $event): bool
    {
        $verb = strtolower((string)($event['verb'] ?? $event['action'] ?? ''));
        $msg  = strtolower((string)($event['message'] ?? ''));

        return $verb === 'edit_complete'
            || str_contains($msg, 'was edited')
            || str_contains($msg, 'were edited')
            || str_contains($msg, 'item was added')
            || str_contains($msg, 'item was removed')
            || str_contains($msg, 'discount was added')
            || str_contains($msg, 'discount was removed')
            || str_contains($msg, 'note was updated')
            || str_contains($msg, 'custom attributes');
    }
}
