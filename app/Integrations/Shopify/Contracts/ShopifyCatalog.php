<?php

namespace App\Integrations\Shopify\Contracts;

use App\Models\Store;

/**
 * Product, variant and inventory candidates.
 */
interface ShopifyCatalog
{
    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function productCompletenessCandidates(Store $store): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function skuDuplicatesCandidates(Store $store): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function inventoryOversellCandidates(Store $store): array;

    /** @return array{products: list<array<string, mixed>>, orders: list<array<string, mixed>>, product_pages: int, order_pages: int, products_truncated: bool, orders_truncated: bool} */
    public function inventoryAgingCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{products: list<array<string, mixed>>, orders: list<array<string, mixed>>, product_pages: int, order_pages: int, products_truncated: bool, orders_truncated: bool} */
    public function inventoryForecastCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function zombieProductsCandidates(Store $store): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function catalogQualityCandidates(Store $store): array;
}
