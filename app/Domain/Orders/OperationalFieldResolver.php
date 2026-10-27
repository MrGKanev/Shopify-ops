<?php

namespace App\Domain\Orders;

use App\Models\OperationalField;
use JsonException;

/**
 * Reads one named operational value. "missing" means the source was read and holds nothing; "unreadable" means it could not be read or interpreted.
 */
class OperationalFieldResolver
{
    public const MAX_LENGTH = 500;

    /**
     * @param  list<array<string, mixed>>|null  $metafields  Null when the Shopify metafields could not be loaded for this order.
     * @param  array<string, mixed>|null  $shipStationOrder
     * @return array{state: string, value: string}
     */
    public function resolve(string $sourceType, string $source, ?array $metafields, ?array $shipStationOrder): array
    {
        if ($sourceType === OperationalField::SOURCE_CUSTOM_FIELD) {
            $advanced = $shipStationOrder['advancedOptions'] ?? null;

            return is_array($advanced) ? $this->interpret($advanced[$source] ?? null, '') : $this->unreadable();
        }
        if ($metafields === null) {
            return $this->unreadable();
        }
        foreach ($metafields as $metafield) {
            if (($metafield['namespace'] ?? '').'.'.($metafield['key'] ?? '') === $source) {
                return $this->interpret($metafield['value'] ?? null, is_string($metafield['type'] ?? null) ? $metafield['type'] : '');
            }
        }

        return ['state' => 'missing', 'value' => ''];
    }

    /** @return array{state: string, value: string} */
    private function interpret(mixed $raw, string $type): array
    {
        if ($raw === null) {
            return ['state' => 'missing', 'value' => ''];
        }
        if (! is_scalar($raw)) {
            return $this->unreadable();
        }
        $value = trim((string) $raw);
        if ($value === '') {
            return ['state' => 'missing', 'value' => ''];
        }
        if (str_starts_with($type, 'json') || str_starts_with($type, 'list.') || $type === 'rich_text_field') {
            try {
                json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $this->unreadable();
            }
        }
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1 || mb_strlen($value) > self::MAX_LENGTH) {
            return $this->unreadable();
        }

        return ['state' => 'present', 'value' => $value];
    }

    /** @return array{state: string, value: string} */
    private function unreadable(): array
    {
        return ['state' => 'unreadable', 'value' => ''];
    }
}
