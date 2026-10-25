<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Concerns\NormalizesText;

class InventoryForecastAnalyzer
{
    use NormalizesText;

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  list<array<string, mixed>>  $orders
     * @param  list<array{alias: string, master: string}>  $aliases
     * @return array{alias_conflicts: list<string>, rows: list<array<string, mixed>>, variants: int, critical: int, warning: int}
     */
    public function analyze(array $products, array $orders, array $aliases = []): array
    {
        $skuMap = new SkuAliasMap($aliases);
        $inventoryCounts = [];
        $eligibleMasters = [];
        foreach ($products as $product) {
            foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
                if (is_array($variant)) {
                    $rawSku = $this->text($variant['sku'] ?? null);
                    $canonical = $skuMap->canonical($rawSku);
                    if ($rawSku === $canonical && ($variant['inventoryItem']['tracked'] ?? false) === true && mb_strtolower($this->text($variant['inventoryPolicy'] ?? null)) !== 'continue') {
                        $eligibleMasters['sku:'.$canonical] = true;
                    }
                    $inventoryCounts['sku:'.$canonical] = ($inventoryCounts['sku:'.$canonical] ?? 0) + 1;
                }
            }
        }
        $sales = [];
        $salesSources = [];
        foreach ($orders as $order) {
            if ($this->text($order['cancelled_at'] ?? null) !== '') {
                continue;
            }
            foreach (is_array($order['line_items'] ?? null) ? $order['line_items'] : [] as $lineItem) {
                if (! is_array($lineItem)) {
                    continue;
                }
                $rawSku = $this->text($lineItem['sku'] ?? null);
                $sku = $skuMap->canonical($rawSku);
                if ($sku !== '') {
                    $sales[$sku] = ($sales[$sku] ?? 0) + (int) ($lineItem['quantity'] ?? 1);
                    $salesSources[$sku]['sku:'.$rawSku] = $rawSku;
                }
            }
        }

        $conflicts = [];
        foreach ($aliases as $mapping) {
            $master = $mapping['master'];
            if (($inventoryCounts['sku:'.$master] ?? 0) !== 1 || ! isset($eligibleMasters['sku:'.$master])) {
                $conflicts['sku:'.$master] = $master;
            }
        }
        $rows = [];
        $variantCount = 0;
        foreach ($products as $product) {
            foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
                $variantCount++;
                if (! is_array($variant) || ($variant['inventoryItem']['tracked'] ?? false) !== true || mb_strtolower($this->text($variant['inventoryPolicy'] ?? null)) === 'continue') {
                    continue;
                }
                $sku = $this->text($variant['sku'] ?? null);
                $master = $skuMap->canonical($sku);
                if ($skuMap->isMapped($sku) && ($master !== $sku || isset($conflicts['sku:'.$master]))) {
                    $conflicts['sku:'.$master] = $master;

                    continue;
                }
                $stock = (int) ($variant['inventoryQuantity'] ?? 0);
                $sold = (int) ($sales[$sku] ?? 0);
                if ($sold === 0 && $stock > 30) {
                    continue;
                }
                $dailyRate = round($sold / 30, 3);
                $daysToZero = $dailyRate > 0 && $stock > 0 ? (int) ceil($stock / $dailyRate) : null;
                if ($daysToZero === null && $sold === 0) {
                    continue;
                }
                $id = $this->text($product['legacyResourceId'] ?? null);
                $rows[] = ['product_id' => ctype_digit($id) ? $id : '', 'product_title' => $this->text($product['title'] ?? null), 'variant_title' => $this->text($variant['title'] ?? null), 'sku' => $sku, 'source_skus' => array_values($salesSources[$sku] ?? []), 'stock' => $stock, 'sold_30d' => $sold, 'daily_rate' => $dailyRate, 'days_to_zero' => $daysToZero];
            }
        }
        usort($rows, function (array $left, array $right): int {
            if ($left['days_to_zero'] === null) {
                return $right['days_to_zero'] === null ? 0 : 1;
            }

            return $right['days_to_zero'] === null ? -1 : $left['days_to_zero'] <=> $right['days_to_zero'];
        });

        return [
            'alias_conflicts' => array_values($conflicts),
            'rows' => $rows,
            'variants' => $variantCount,
            'critical' => count(array_filter($rows, fn (array $row): bool => $row['days_to_zero'] !== null && $row['days_to_zero'] < 7)),
            'warning' => count(array_filter($rows, fn (array $row): bool => $row['days_to_zero'] !== null && $row['days_to_zero'] >= 7 && $row['days_to_zero'] < 14)),
        ];
    }
}
