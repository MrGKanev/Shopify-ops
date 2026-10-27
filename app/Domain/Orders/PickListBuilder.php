<?php

namespace App\Domain\Orders;

class PickListBuilder
{
    public const COLUMNS = ['location' => 'Bin', 'sku' => 'SKU', 'name' => 'Item', 'options' => 'Variant', 'quantity' => 'Qty', 'orders' => 'Orders', 'image' => 'Image'];

    public const DEFAULT_COLUMNS = ['location', 'sku', 'name', 'options', 'quantity', 'orders'];

    /**
     * Combines items across orders by SKU and variant options. Items without a SKU are only combined with identical
     * name and options, and are reported as incomplete; bins are never invented.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return array{lines: list<array{sku: string, name: string, options: string, location: string, image: string, quantity: int, allocations: list<array{order: string, quantity: int}>}>, totalQuantity: int, warnings: list<string>}
     */
    public function build(array $orders, string $sort = 'location'): array
    {
        $lines = [];
        $warnings = [];
        foreach ($orders as $order) {
            $orderNumber = $this->text($order['orderNumber'] ?? '');
            foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
                if (! is_array($item) || ! is_numeric($item['quantity'] ?? null) || (int) $item['quantity'] < 1) {
                    continue;
                }
                $sku = $this->text($item['sku'] ?? '');
                $name = $this->text($item['name'] ?? '');
                $options = $this->options($item['options'] ?? []);
                $location = $this->text($item['warehouseLocation'] ?? '');
                $key = mb_strtolower($sku === '' ? "name\0".$name."\0".$options : "sku\0".$sku."\0".$options);
                $lines[$key] ??= ['sku' => $sku, 'name' => $name, 'options' => $options, 'location' => $location, 'image' => $this->text($item['imageUrl'] ?? ''), 'quantity' => 0, 'allocations' => []];
                $lines[$key]['location'] = $lines[$key]['location'] !== '' ? $lines[$key]['location'] : $location;
                $lines[$key]['quantity'] += (int) $item['quantity'];
                $lines[$key]['allocations'][$orderNumber] = ($lines[$key]['allocations'][$orderNumber] ?? 0) + (int) $item['quantity'];
            }
        }
        $lines = array_values(array_map(function (array $line): array {
            $line['allocations'] = array_map(fn (string|int $order, int $quantity): array => ['order' => (string) $order, 'quantity' => $quantity], array_keys($line['allocations']), $line['allocations']);

            return $line;
        }, $lines));
        usort($lines, fn (array $a, array $b): int => $this->compare($a, $b, $sort));
        foreach ($lines as $line) {
            if ($line['sku'] === '') {
                $warnings[] = __('":name" has no SKU and was only combined with identical names and options.', ['name' => $line['name']]);
            }
            if ($line['location'] === '') {
                $warnings[] = __('":item" has no bin location.', ['item' => $line['sku'] !== '' ? $line['sku'] : $line['name']]);
            }
        }

        return ['lines' => $lines, 'totalQuantity' => array_sum(array_column($lines, 'quantity')), 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compare(array $a, array $b, string $sort): int
    {
        $primary = $sort === 'sku' ? [$a['sku'], $b['sku']] : [$a['location'], $b['location']];
        if ($sort !== 'sku' && ($a['location'] === '') !== ($b['location'] === '')) {
            return $a['location'] === '' ? 1 : -1;
        }

        return strnatcasecmp($primary[0], $primary[1]) ?: strnatcasecmp($a['sku'].$a['name'], $b['sku'].$b['name']) ?: strnatcasecmp($a['options'], $b['options']);
    }

    private function options(mixed $options): string
    {
        return collect(is_array($options) ? $options : [])->filter(fn (mixed $option): bool => is_array($option))
            ->map(fn (array $option): string => $this->text($option['name'] ?? '').': '.$this->text($option['value'] ?? ''))
            ->filter(fn (string $option): bool => $option !== ': ')->implode(', ');
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
