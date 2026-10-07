<?php

namespace App\Domain\Orders;

class ShipStationRelevantFingerprint
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public static function fields(array $payload): array
    {
        $address = [];
        foreach (['name', 'company', 'street1', 'street2', 'city', 'state', 'postalCode', 'country', 'phone'] as $field) {
            $value = $payload['shipTo'][$field] ?? '';
            $address[$field] = mb_strtolower(trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : '') ?? ''));
        }
        $items = [];
        foreach ($payload['items'] ?? [] as $item) {
            if (($item['adjustment'] ?? false) || (int) ($item['quantity'] ?? 0) === 0) {
                continue;
            }
            $key = (string) ($item['lineItemKey'] ?? '');
            $key = $key !== '' ? basename($key) : 'sku:'.trim((string) ($item['sku'] ?? '')).'|'.trim((string) ($item['name'] ?? ''));
            $items[] = ['key' => $key, 'sku' => trim((string) ($item['sku'] ?? '')), 'quantity' => (int) ($item['quantity'] ?? 0)];
        }
        usort($items, fn (array $a, array $b): int => $a <=> $b);

        return ['shipTo' => $address, 'items' => $items, 'requestedShippingService' => trim((string) ($payload['requestedShippingService'] ?? ''))];
    }

    /** @param array<string, mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::fields($payload), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @return array<string, array{shopify: mixed, shipstation: mixed}> */
    public static function diff(array $expected, array $actual): array
    {
        $diff = [];
        $expectedPayload = $expected;
        $actualPayload = $actual;
        $expected = self::fields($expected);
        $actual = self::fields($actual);
        foreach ($expected['shipTo'] as $key => $value) {
            if ($value !== $actual['shipTo'][$key]) {
                $diff['shipTo.'.$key] = ['shopify' => $expectedPayload['shipTo'][$key] ?? '', 'shipstation' => $actualPayload['shipTo'][$key] ?? ''];
            }
        }
        foreach (['items', 'requestedShippingService'] as $key) {
            if ($expected[$key] !== $actual[$key]) {
                $diff[$key] = ['shopify' => $expected[$key], 'shipstation' => $actual[$key]];
            }
        }

        return $diff;
    }
}
