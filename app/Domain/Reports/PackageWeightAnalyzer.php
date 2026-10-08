<?php

namespace App\Domain\Reports;

class PackageWeightAnalyzer
{
    public function __construct(private readonly CustomsReadinessAnalyzer $weights, private readonly ProductSyncAnalyzer $products) {}

    /** @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $order
     * @param array<string, mixed> $shipment
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $input
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>} */
    public function analyze(array $lines, array $order, array $shipment, array $products, array $input, bool $complete): array
    {
        $rows = [];
        $notes = [];
        $totals = ['Shopify current' => 0.0, 'Imported SS item' => 0.0, 'Shipment item snapshot' => 0.0];
        $known = array_fill_keys(array_keys($totals), $complete);
        $items = $shipment['shipmentItems'] ?? null;
        if (! is_array($items) || $items === [] || ($order['advancedOptions']['mergedOrSplit'] ?? false)) {
            $items = [];
            $known = array_fill_keys(array_keys($totals), false);
            $notes[] = 'Package contents or merged/split order mapping are unconfirmed.';
        }
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $known = array_fill_keys(array_keys($totals), false);
                $notes[] = 'Malformed shipment item prevents a complete package comparison.';

                continue;
            }
            if (($item['adjustment'] ?? false) === true) {
                continue;
            }
            $quantity = $item['quantity'] ?? null;
            $imported = $this->match($item, $order['items'] ?? [], 'orderItemId', 'orderItemId');
            $line = $imported === null ? null : $this->match($imported, $lines, 'lineItemKey', 'id');
            $findings = [];
            $id = $line['id'] ?? null;
            $validQuantity = is_int($quantity) && $quantity > 0 && $line !== null && ($line['requiresShipping'] ?? null) === true && is_int($line['currentQuantity'] ?? null)
                && $quantity <= $line['currentQuantity'] && $quantity <= (int) ($imported['quantity'] ?? 0);
            if (! $validQuantity || $id === null || isset($seen[$id])) {
                $known = array_fill_keys(array_keys($totals), false);
                $findings[] = 'Shipment items cannot be uniquely mapped to current order quantities.';
            }
            if ($id !== null) {
                $seen[$id] = true;
            }
            $variant = is_array($line['variant'] ?? null) ? $line['variant'] : [];
            $sku = trim((string) ($item['sku'] ?? ''));
            $defaults = array_values(array_filter($products, fn (array $product): bool => $sku !== '' && trim((string) ($product['sku'] ?? '')) === $sku));
            $current = $this->variantGrams($variant);
            $importedGrams = $this->weights->grams(data_get($imported, 'weight.value'), data_get($imported, 'weight.units'));
            $snapshotGrams = $this->weights->grams(data_get($item, 'weight.value'), data_get($item, 'weight.units'));
            $sources = [
                'Shopify current' => ['grams' => $current, 'description' => (string) ($line['title'] ?? ''), 'hs' => (string) data_get($variant, 'inventoryItem.harmonizedSystemCode', ''), 'origin' => (string) data_get($variant, 'inventoryItem.countryCodeOfOrigin', '')],
                'Imported SS item' => ['grams' => $importedGrams, 'description' => (string) ($imported['name'] ?? ''), 'hs' => '', 'origin' => ''],
                'Shipment item snapshot' => ['grams' => $snapshotGrams, 'description' => (string) ($item['name'] ?? ''), 'hs' => '', 'origin' => ''],
                'ShipStation default' => count($defaults) === 1 ? $this->products->ssSource($defaults[0]) : ['grams' => null, 'description' => '', 'hs' => '', 'origin' => ''],
            ];
            foreach ($totals as $source => $total) {
                $grams = $sources[$source]['grams'];
                if ($grams === null || ! $validQuantity) {
                    $known[$source] = false;
                } else {
                    $totals[$source] = $total + $grams * $quantity;
                }
            }
            if (count($defaults) !== 1) {
                $findings[] = 'No unique ShipStation product default for this SKU.';
            }
            if (($variant['requiresComponents'] ?? null) === true) {
                $findings[] = $current === null ? 'Bundle component weight coverage is incomplete.' : 'Shopify weight uses current bundle component quantities, not the parent default.';
            }
            if ($importedGrams !== null && count($defaults) === 1 && $sources['ShipStation default']['grams'] !== null && abs($importedGrams - $sources['ShipStation default']['grams']) > 0.1) {
                $findings[] = 'Imported order item weight differs from the current ShipStation product default.';
            }
            if ($snapshotGrams === null) {
                $findings[] = 'Shipment item weight is unavailable; current defaults do not prove the label-time item weight.';
            }
            $rows[] = ['sku' => $sku, 'quantity' => is_int($quantity) ? $quantity : null, 'title' => (string) ($item['name'] ?? ''), 'sources' => $sources, 'findings' => $findings, 'components' => data_get($variant, 'productVariantComponents.nodes', [])];
        }
        if ($rows === []) {
            $known = array_fill_keys(array_keys($totals), false);
        }
        $tare = isset($input['tare']) ? ((float) $input['tare'] === 0.0 ? 0.0 : $this->weights->grams($input['tare'], $input['tare_unit'])) : null;
        $declared = $this->weights->grams(data_get($shipment, 'weight.value'), data_get($shipment, 'weight.units'));
        $comparisons = [];
        foreach ($totals as $source => $total) {
            $goods = $known[$source] && is_finite($total) ? $total : null;
            $expected = $goods !== null && $tare !== null ? $goods + $tare : null;
            $delta = $expected !== null && $declared !== null ? $declared - $expected : null;
            $comparisons[$source] = ['goods_grams' => $goods, 'package_grams' => $expected, 'delta_grams' => $delta, 'finding' => $delta !== null && abs($delta) > 0.1 ? 'Declared weight differs from this source estimate; review packaging and source data.' : null];
        }
        if ($tare === null) {
            $notes[] = 'Tare is unknown; goods weight cannot establish a complete package weight.';
        }
        if ($declared === null) {
            $notes[] = 'Declared shipment weight is missing or its unit is unconfirmed.';
        }
        $dim = $this->dimGrams($shipment['dimensions'] ?? null, $input);
        if (isset($input['dim_divisor']) && $dim === null) {
            $notes[] = 'DIM scenario requires complete dimensions with confirmed units.';
        }

        return ['rows' => $rows, 'summary' => ['declared_grams' => $declared, 'tare_grams' => $tare, 'comparisons' => $comparisons, 'dim_grams' => $dim, 'billable_scenario_grams' => $dim !== null && $declared !== null ? max($dim, $declared) : null, 'findings' => $notes, 'dimensions' => $shipment['dimensions'] ?? null, 'measured_grams' => null, 'carrier_adjustment' => null]];
    }

    /** @param array<string, mixed> $variant */
    public function variantGrams(array $variant): ?float
    {
        if (($variant['requiresComponents'] ?? null) === false) {
            return $this->weights->grams(data_get($variant, 'inventoryItem.measurement.weight.value'), data_get($variant, 'inventoryItem.measurement.weight.unit'));
        }
        $components = data_get($variant, 'productVariantComponents');
        if (! is_array($components) || ($components['pageInfo']['hasNextPage'] ?? null) !== false || ! is_array($components['nodes'] ?? null) || $components['nodes'] === []) {
            return null;
        }
        $sum = 0.0;
        $seen = [];
        foreach ($components['nodes'] as $component) {
            $child = $component['productVariant'] ?? [];
            if (! is_array($child) || empty($child['id']) || isset($seen[$child['id']]) || ($child['requiresComponents'] ?? null) !== false || ! is_int($component['quantity'] ?? null) || $component['quantity'] < 1 || ! is_bool(data_get($child, 'inventoryItem.requiresShipping'))) {
                return null;
            }
            $seen[$child['id']] = true;
            if (data_get($child, 'inventoryItem.requiresShipping') === false) {
                continue;
            }
            $grams = $this->variantGrams($child);
            if ($grams === null) {
                return null;
            }
            $sum += $grams * $component['quantity'];
        }

        return is_finite($sum) && $sum > 0 ? $sum : null;
    }

    /** @param array<string, mixed> $item
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>|null */
    private function match(array $item, array $candidates, string $key, string $target): ?array
    {
        $identity = preg_replace('~^gid://shopify/LineItem/~', '', (string) ($item[$key] ?? ''));
        $sku = trim((string) ($item['sku'] ?? ''));
        $matches = array_values(array_filter($candidates, fn (array $candidate): bool => $identity !== ''
            ? preg_replace('~^gid://shopify/LineItem/~', '', (string) ($candidate[$target] ?? '')) === $identity
            : $sku !== '' && trim((string) ($candidate['sku'] ?? '')) === $sku));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string, mixed> $input */
    private function dimGrams(mixed $dimensions, array $input): ?float
    {
        if (! isset($input['dim_divisor']) || ! is_array($dimensions)) {
            return null;
        }
        $unit = strtolower((string) ($dimensions['units'] ?? ''));
        $factor = match ($unit) {
            'inches' => $input['dim_basis'] === 'cm_kg' ? 2.54 : 1.0, 'centimeters' => $input['dim_basis'] === 'in_lb' ? 1 / 2.54 : 1.0, default => null
        };
        if ($factor === null) {
            return null;
        }
        $volume = 1.0;
        foreach (['length', 'width', 'height'] as $field) {
            if (! is_numeric($dimensions[$field] ?? null) || ! is_finite((float) $dimensions[$field]) || (float) $dimensions[$field] <= 0) {
                return null;
            }
            $volume *= (float) $dimensions[$field] * $factor;
        }
        $grams = $volume / (float) $input['dim_divisor'] * ($input['dim_basis'] === 'cm_kg' ? 1000 : 453.59237);

        return is_finite($grams) ? $grams : null;
    }
}
