<?php

namespace App\Domain\Reports;

class SkuAliasMap
{
    /** @var array<string, string> */
    private array $masters = [];

    /** @param list<array{alias: string, master: string}> $mappings */
    public function __construct(array $mappings = [])
    {
        foreach ($mappings as $mapping) {
            $this->masters['sku:'.$mapping['alias']] = $mapping['master'];
        }
    }

    public function canonical(string $sku): string
    {
        return $this->masters['sku:'.$sku] ?? $sku;
    }

    public function isMapped(string $sku): bool
    {
        return isset($this->masters['sku:'.$sku]) || in_array($sku, $this->masters, true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $totals
     */
    public function add(array &$totals, string $product, string $sku, int $quantity): void
    {
        if ($this->masters === []) {
            if ($product === '') {
                return;
            }
            $sku = '';
        }
        $master = $this->canonical($sku);
        $key = $sku === '' ? 'name:'.$product : 'sku:'.$master;
        if (! isset($totals[$key])) {
            $totals[$key] = ['product' => $this->isMapped($sku) ? $master : $product, 'quantity' => 0];
            if ($sku !== '') {
                $totals[$key] += ['sku' => $master, 'source_skus' => []];
            }
        }
        $totals[$key]['quantity'] += $quantity;
        if ($sku !== '' && ! in_array($sku, $totals[$key]['source_skus'], true)) {
            $totals[$key]['source_skus'][] = $sku;
            sort($totals[$key]['source_skus'], SORT_STRING);
        }
    }

    /** @param array<string, array<string, mixed>> $totals
     * @return list<array<string, mixed>>
     */
    public function rows(array $totals): array
    {
        $rows = array_values($totals);
        usort($rows, fn (array $left, array $right): int => [$left['product'], $left['sku'] ?? ''] <=> [$right['product'], $right['sku'] ?? '']);

        return $rows;
    }
}
