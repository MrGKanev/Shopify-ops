<?php

namespace App\Integrations\Shopify\Contracts;

use App\Models\Store;

/**
 * Refunds, disputes, gift cards and tax candidates.
 */
interface ShopifyPayments
{
    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function taxAuditCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{disputes: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function openDisputes(Store $store): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function repeatRefundCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function refundTrackerCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function returnedItemCandidates(Store $store, string $startDate): array;

    /** @return array{gift_cards: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function giftCardCandidates(Store $store): array;
}
