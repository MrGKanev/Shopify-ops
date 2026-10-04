<?php

namespace App\Integrations\Shopify\Contracts;

use App\Models\Store;

/**
 * Customer history, lifetime value and consent candidates.
 */
interface ShopifyCustomers
{
    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function customerLtvCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, customer: array<string, mixed>|null, pages: int, truncated: bool} */
    public function customerOrderHistory(Store $store, string $email): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function consentAuditCandidates(Store $store, string $startDate, string $endDate): array;
}
