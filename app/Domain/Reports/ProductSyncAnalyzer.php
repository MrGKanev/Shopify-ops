<?php

namespace App\Domain\Reports;

class ProductSyncAnalyzer
{
    public function __construct(private readonly CustomsReadinessAnalyzer $customs) {}

    /** @param list<array<string, mixed>> $variants
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>> */
    public function catalog(array $variants, array $products, bool $shopifyComplete, bool $ssComplete): array
    {
        $rows = [];
        $shopifySkus = [];
        foreach ($variants as $variant) {
            $sku = trim((string) ($variant['sku'] ?? ''));
            $shopifySkus[$sku] = ($shopifySkus[$sku] ?? 0) + 1;
        }
        foreach ($variants as $variant) {
            $sku = trim((string) ($variant['sku'] ?? ''));
            $matches = array_values(array_filter($products, fn (array $product): bool => $sku !== '' && trim((string) ($product['sku'] ?? '')) === $sku));
            $inventory = $variant['inventoryItem'] ?? [];
            $inventory['duplicateSkuCount'] = max((int) ($inventory['duplicateSkuCount'] ?? 0), ($shopifySkus[$sku] ?? 1) - 1);
            $variant = [...$variant, 'inventoryItem' => $inventory, 'customs_title' => trim((string) data_get($variant, 'product.title', '').' / '.($variant['title'] ?? ''))];
            $row = $this->customs->variant($variant, $products, $ssComplete);
            if ($row === null) {
                continue;
            }
            $findings = array_values(array_filter($row['findings'], fn (string $finding): bool => $finding !== 'No positive declared-value candidate in the available sources.'));
            if ($matches === []) {
                $findings[] = $ssComplete ? 'SKU has no active ShipStation product counterpart.' : 'SKU was not found in the incomplete ShipStation scan.';
            }
            if (count($matches) === 1 && $row['sources']['Shopify']['description'] !== $row['sources']['ShipStation default']['description']) {
                $findings[] = 'Shopify description candidate differs from the ShipStation customs description.';
            }
            if (($variant['requiresComponents'] ?? null) === true) {
                $findings[] = 'Bundle parent default weight is not a confirmed component weight total.';
            }
            if (count($matches) > 1) {
                foreach ($matches as $candidate) {
                    $row['sources']['ShipStation candidate #'.($candidate['productId'] ?? '')] = $this->ssSource($candidate);
                }
            }
            $rows[] = [...$row, 'side' => 'Shopify', 'shopify_count' => $shopifySkus[$sku], 'ss_count' => count($matches), 'findings' => array_values(array_unique($findings))];
        }
        foreach ($products as $product) {
            $sku = trim((string) ($product['sku'] ?? ''));
            if ($sku !== '' && isset($shopifySkus[$sku])) {
                continue;
            }
            $matches = array_filter($products, fn (array $candidate): bool => trim((string) ($candidate['sku'] ?? '')) === $sku);
            $findings = [$shopifyComplete ? 'Account-wide ShipStation SKU has no Shopify counterpart in this store.' : 'ShipStation SKU was not found in the incomplete Shopify scan.'];
            if ($sku === '') {
                $findings[] = 'Missing SKU prevents matching ShipStation defaults.';
            }
            if (count($matches) > 1) {
                $findings[] = 'Duplicate SKU prevents choosing a ShipStation product default.';
            }
            $rows[] = ['id' => (string) ($product['productId'] ?? ''), 'sku' => $sku, 'title' => (string) ($product['name'] ?? ''), 'side' => 'ShipStation account', 'shopify_count' => 0, 'ss_count' => count($matches), 'sources' => ['ShipStation default' => $this->ssSource($product)], 'findings' => $findings];
        }

        return $rows;
    }

    /** @param array<string, mixed> $product
     * @return array<string, mixed> */
    public function ssSource(array $product): array
    {
        return ['grams' => $this->customs->grams($product['weightOz'] ?? null, 'ounces'), 'description' => (string) ($product['customsDescription'] ?? ''), 'hs' => (string) ($product['customsTariffNo'] ?? ''), 'origin' => (string) ($product['customsCountryCode'] ?? '')];
    }
}
