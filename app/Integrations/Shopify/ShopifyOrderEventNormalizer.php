<?php

namespace App\Integrations\Shopify;

class ShopifyOrderEventNormalizer
{
    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function normalize(array $event, string $fallbackOrderGid): array
    {
        $subjectGid = (string) ($event['subjectId'] ?? '');

        if ($subjectGid === '') {
            $subjectGid = $fallbackOrderGid;
        }

        $action = mb_strtolower((string) ($event['action'] ?? ''));

        return [
            'id' => $this->legacyId($event['id'] ?? null),
            'admin_graphql_api_id' => $event['id'] ?? '',
            'verb' => $action,
            'action' => $action,
            'created_at' => $event['createdAt'] ?? '',
            'message' => (string) ($event['message'] ?? ''),
            'subject_id' => $this->legacyId($subjectGid),
            'subject_type' => mb_strtolower((string) ($event['subjectType'] ?? 'Order')),
            'subject_graphql_api_id' => $subjectGid,
            'app_title' => $event['appTitle'] ?? '',
        ];
    }

    /** @param array<string, mixed> $event */
    public function isAddressChangeEvent(array $event): bool
    {
        $haystack = mb_strtolower(trim(($event['verb'] ?? '').' '.($event['action'] ?? '').' '.($event['message'] ?? '')));

        return str_contains($haystack, 'shipping address') || str_contains($haystack, 'address was') || str_contains($haystack, 'shipping_address');
    }

    /**
     * Address changes are tracked separately (isAddressChangeEvent()), so an
     * event that looks like both (e.g. an edit_complete event whose message
     * mentions the shipping address) must not also count here - otherwise it
     * would double-count into both Address Changes and Order Edit History
     * for the same underlying event.
     *
     * @param  array<string, mixed>  $event
     */
    public function isOrderEditEvent(array $event): bool
    {
        if ($this->isAddressChangeEvent($event)) {
            return false;
        }

        $verb = mb_strtolower((string) ($event['verb'] ?? $event['action'] ?? ''));
        $message = mb_strtolower((string) ($event['message'] ?? ''));

        return $verb === 'edit_complete'
            || str_contains($message, 'was edited')
            || str_contains($message, 'were edited')
            || str_contains($message, 'item was added')
            || str_contains($message, 'item was removed')
            || str_contains($message, 'discount was added')
            || str_contains($message, 'discount was removed')
            || str_contains($message, 'note was updated')
            || str_contains($message, 'custom attributes');
    }

    private function legacyId(mixed $graphqlId): int|string
    {
        $id = '';

        if (is_string($graphqlId) && preg_match('~/([0-9]+)(?:\?.*)?$~', $graphqlId, $matches) === 1) {
            $id = $matches[1];
        }

        return ctype_digit($id) ? (int) $id : $id;
    }
}
