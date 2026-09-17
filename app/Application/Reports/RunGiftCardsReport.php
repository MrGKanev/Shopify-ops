<?php

namespace App\Application\Reports;

use App\Domain\Reports\GiftCardsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunGiftCardsReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly GiftCardsAnalyzer $analyzer) {}

    /** @return ScanResult<array{masked_code: string, customer_email: string, initial_value: float|string, balance: float|string, currency: string, expires_on: ?string, reasons: list<string>}> */
    public function handle(Store $store, int $days, int $now): ScanResult
    {
        $result = $this->shopify->giftCardCandidates($store);

        return new ScanResult(scanned: count($result['gift_cards']), rows: $this->analyzer->analyze($result['gift_cards'], $days, $now), pages: $result['pages'], truncated: $result['truncated'], days: $days);
    }
}
