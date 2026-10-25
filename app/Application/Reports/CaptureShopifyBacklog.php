<?php

namespace App\Application\Reports;

use App\Domain\Reports\ShopifyBacklogAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\AuditSnapshot;
use App\Models\Store;
use Carbon\CarbonImmutable;
use LogicException;
use UnexpectedValueException;

class CaptureShopifyBacklog
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly ShopifyBacklogAnalyzer $analyzer) {}

    public function handle(Store $store): AuditSnapshot
    {
        $capturedAt = CarbonImmutable::now($store->shopTimezone());
        $identity = ['tool' => 'shopify_backlog', 'report_date' => $capturedAt->startOfDay()];
        $account = hash('sha256', $store->shopify_store.'|'.$store->shopify_access_token);
        $existing = $store->auditSnapshots()->where($identity)->first();
        if ($existing !== null) {
            if (($existing->result['account_fingerprint'] ?? null) !== $account) {
                throw new LogicException('The Shopify account changed after today’s backlog observation.');
            }

            return $existing;
        }
        if ($store->missingShopifyCredentials()) {
            throw new LogicException('Shopify credentials are required for backlog history.');
        }
        $candidates = $this->shopify->shipmentBacklogCandidates($store);
        if ($candidates['truncated'] || $candidates['scopes'] === []) {
            throw new UnexpectedValueException('Shopify backlog coverage could not be confirmed.');
        }
        $analysis = $this->analyzer->analyze($candidates['fulfillment_orders']);

        return $store->auditSnapshots()->firstOrCreate($identity, [
            'start_date' => $identity['report_date'], 'end_date' => $identity['report_date'], 'rows_found' => $analysis['orders'],
            'result' => [...$analysis, 'captured_at' => $capturedAt->toIso8601String(), 'completed_at' => CarbonImmutable::now($store->shopTimezone())->toIso8601String(), 'timezone' => $store->shopTimezone(), 'source' => 'shopify', 'account_fingerprint' => $account, 'scopes' => $candidates['scopes'], 'pages' => $candidates['pages']],
        ]);
    }
}
