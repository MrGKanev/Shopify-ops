<?php

namespace App\Application\Reports;

use App\Domain\Reports\RefundTrackerAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;

class RunRefundTrackerReport
{
    public function __construct(
        private readonly ShopifyPayments $shopify,
        private readonly ShipStationClientFactory $shipStationFactory,
        private readonly RefundTrackerAnalyzer $analyzer,
    ) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $candidates = $this->shopify->refundTrackerCandidates($store, $startDate, $endDate);
        $shipStation = $this->shipStationFactory->forStore($store);
        $shipStationOrders = $shipStation?->fetchAllOrders($startDate, now()->parse($endDate)->addDays(7)->toDateString()) ?? [];
        $rows = $this->analyzer->analyze($candidates['orders'], $shipStationOrders);

        return new ReportResult(rows: $rows, scanned: count($candidates['orders']), pages: $candidates['pages'], truncated: $candidates['truncated'], params: ['startDate' => $startDate, 'endDate' => $endDate], meta: ['missing' => count(array_filter($rows, fn (array $row): bool => $row['risk'] === 'missing')), 'active' => count(array_filter($rows, fn (array $row): bool => $row['risk'] === 'active')), 'hasShipStation' => $shipStation !== null]);
    }
}
