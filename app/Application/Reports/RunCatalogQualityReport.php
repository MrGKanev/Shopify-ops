<?php

namespace App\Application\Reports;

use App\Domain\Reports\CatalogQualityAnalyzer;
use App\Domain\Reports\CustomsReadinessAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Integrations\Shopify\ShopifyCustoms;
use App\Models\Store;
use Throwable;

class RunCatalogQualityReport extends RunScanReport
{
    public function __construct(private readonly ShopifyCatalog $shopify, private readonly CatalogQualityAnalyzer $analyzer, private readonly ShopifyCustoms $customs, private readonly ShipStationClientFactory $shipStation, private readonly CustomsReadinessAnalyzer $readiness) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, bool $includeCustoms = false, ?string $after = null): ReportResult
    {
        if (! $includeCustoms) {
            $result = $this->shopify->catalogQualityCandidates($store);

            return $this->scanResult($result, 'products', $this->analyzer->analyze($result['products']));
        }
        $data = $this->customs->catalog($store, $after);
        $defaults = [];
        $ssComplete = false;
        $findings = [];
        try {
            $ss = $this->shipStation->forStore($store)?->customsProducts();
            if ($ss !== null) {
                $defaults = $ss['products'];
                $ssComplete = ! $ss['truncated'];
            }
        } catch (Throwable) {
            $findings[] = 'ShipStation product defaults could not be loaded.';
        }
        $rows = [];
        $reviewProducts = [];
        foreach ($data['products'] as $product) {
            if ($product['customs_truncated']) {
                $findings[] = 'Some product variants were truncated; their customs coverage is incomplete.';
                $reviewProducts[] = $product['id'];
            }
            foreach ($product['customs_variants'] as $variant) {
                $variant['customs_title'] = trim((is_scalar($product['title'] ?? null) ? (string) $product['title'] : '').' / '.(is_scalar($variant['title'] ?? null) ? (string) $variant['title'] : ''));
                $row = $this->readiness->variant($variant, $defaults, $ssComplete, $data['currency']);
                if ($row !== null) {
                    $rows[] = $row;
                    if ($row['findings'] !== []) {
                        $reviewProducts[] = $product['id'];
                    }
                }
            }
        }
        if (! $ssComplete) {
            $findings[] = 'ShipStation product coverage is incomplete; defaults cannot be confirmed.';
        }
        $customs = ['rows' => $rows, 'declarations' => [], 'findings' => array_values(array_unique($findings)), 'from' => '', 'to' => '', 'carrier' => '', 'service' => '', 'checked_at' => now($store->shopTimezone())->toIso8601String(), 'next_after' => $data['next_after'], 'mode' => 'catalog', 'partial' => $data['truncated'] || ! $ssComplete];

        return new ReportResult(rows: $this->analyzer->analyze($data['products'], array_values(array_unique($reviewProducts))), scanned: count($data['products']), pages: $data['pages'], truncated: $data['truncated'], params: compact('includeCustoms', 'after'), meta: ['customs' => $customs]);
    }
}
