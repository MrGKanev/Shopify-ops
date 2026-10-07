<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Concerns\NormalizesText;

class CatalogQualityAnalyzer
{
    use NormalizesText;

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  list<string>  $customsProductIds
     * @return list<array{id: int|string, title: string, vendor: string, type: string, issues: list<string>}>
     */
    public function analyze(array $products, array $customsProductIds = []): array
    {
        $rows = [];
        foreach ($products as $product) {
            $issues = [];
            $seo = is_array($product['seo'] ?? null) ? $product['seo'] : [];
            if ($this->text($product['onlineStoreUrl'] ?? null) === '') {
                $issues[] = 'Not published to Online Store';
            }
            if ($this->text($seo['title'] ?? null) === '') {
                $issues[] = 'Missing SEO title';
            }
            if ($this->text($seo['description'] ?? null) === '') {
                $issues[] = 'Missing SEO description';
            }
            $collectionConnection = is_array($product['collections'] ?? null) ? $product['collections'] : [];
            $collections = $collectionConnection['nodes'] ?? null;
            if (! is_array($collections) || $collections === []) {
                $issues[] = 'Not in any collection';
            }
            if (in_array($this->text($product['id'] ?? null), $customsProductIds, true)) {
                $issues[] = 'Customs product defaults need review';
            }
            if ($issues === []) {
                continue;
            }
            $id = $this->text($product['legacyResourceId'] ?? null);
            $rows[] = ['id' => ctype_digit($id) ? $id : '', 'title' => $this->text($product['title'] ?? null), 'vendor' => $this->text($product['vendor'] ?? null), 'type' => $this->text($product['productType'] ?? null), 'issues' => $issues];
        }

        return $rows;
    }
}
