<?php

namespace App\Domain\Orders;

class OrderTypeClassifier
{
    /** @param array<string, mixed> $order */
    public function classify(array $order): string
    {
        $matches = [];
        foreach ($this->rules() as $rule) {
            if ($this->orderMatches($order, $rule)) {
                $matches[] = (string) ($rule['name'] ?? 'Unknown');
            }
        }

        return $matches === [] ? (string) config('order-types.fallback', 'Other') : implode(' + ', array_unique($matches));
    }

    /** @param array<string, mixed> $order @return array<string, list<string>> */
    public function missingRequired(array $order): array
    {
        $missing = [];
        foreach ($this->rules() as $rule) {
            $required = is_array($rule['required_items'] ?? null) ? $rule['required_items'] : [];
            if ($required === [] || ! $this->orderMatches($order, $rule) || $this->excluded($order, $rule)) {
                continue;
            }
            foreach ($required as $item) {
                if (is_array($item) && ! $this->orderMatches($order, $item)) {
                    $missing[(string) ($rule['name'] ?? 'Unknown')][] = (string) ($item['label'] ?? 'Unknown');
                }
            }
        }

        return $missing;
    }

    /** @return list<array<string, mixed>> */
    private function rules(): array
    {
        return array_values(array_filter(config('order-types.rules', []), is_array(...)));
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $rule */
    private function orderMatches(array $order, array $rule): bool
    {
        foreach (is_array($order['line_items'] ?? null) ? $order['line_items'] : [] as $item) {
            if (is_array($item) && $this->matches($item, (string) ($rule['match'] ?? ''), $rule['value'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $rule */
    private function excluded(array $order, array $rule): bool
    {
        foreach (is_array($rule['exclude_if'] ?? null) ? $rule['exclude_if'] : [] as $exclusion) {
            if (is_array($exclusion) && $this->orderMatches($order, $exclusion)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $item */
    private function matches(array $item, string $match, mixed $value): bool
    {
        $values = array_map(fn (mixed $candidate): string => mb_strtolower(trim(is_scalar($candidate) ? (string) $candidate : '')), is_array($value) ? $value : [$value]);
        $sku = mb_strtolower(trim(is_scalar($item['sku'] ?? null) ? (string) $item['sku'] : ''));
        $title = mb_strtolower(trim(is_scalar($item['title'] ?? null) ? (string) $item['title'] : ''));
        $vendor = mb_strtolower(trim(is_scalar($item['vendor'] ?? null) ? (string) $item['vendor'] : ''));

        return match ($match) {
            'sku_starts_with' => array_any($values, fn (string $candidate): bool => $candidate !== '' && str_starts_with($sku, $candidate)),
            'sku_contains' => array_any($values, fn (string $candidate): bool => $candidate !== '' && str_contains($sku, $candidate)),
            'sku_not_starts_with' => array_all($values, fn (string $candidate): bool => $candidate === '' || ! str_starts_with($sku, $candidate)),
            'title_contains' => array_any($values, fn (string $candidate): bool => $candidate !== '' && str_contains($title, $candidate)),
            'vendor_is' => in_array($vendor, $values, true),
            default => false,
        };
    }
}
