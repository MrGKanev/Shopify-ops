<?php

namespace App\Domain\Orders;

use App\Models\OperationalFieldFilter;

/**
 * Decides whether an order row satisfies a saved operational-field filter. Missing, unreadable and present stay distinct,
 * and only a readable value can match "contains".
 */
class OperationalFieldFilterMatcher
{
    /** @param  array{fields: list<array{alias: string, state: string, value: string}>}  $row */
    public function matches(array $row, string $alias, string $condition, ?string $text): bool
    {
        foreach ($row['fields'] as $field) {
            if ($field['alias'] !== $alias) {
                continue;
            }

            return match ($condition) {
                'missing', 'unreadable', 'present' => $field['state'] === $condition,
                'contains' => $field['state'] === 'present' && $text !== null && $text !== '' && mb_stripos($field['value'], $text) !== false,
                default => false,
            };
        }

        return false;
    }

    /** @param  array{fields: list<array{alias: string, state: string, value: string}>}  $row */
    public function matchesFilter(array $row, OperationalFieldFilter $filter): bool
    {
        return $this->matches($row, $filter->field->alias, $filter->condition, $filter->text);
    }
}
