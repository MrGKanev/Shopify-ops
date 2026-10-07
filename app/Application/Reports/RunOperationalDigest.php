<?php

namespace App\Application\Reports;

use App\Domain\Reports\OperationalDigestAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Integrations\Shopify\ShopifyQueries;
use App\IssueStatus;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Throwable;
use UnexpectedValueException;

class RunOperationalDigest
{
    public function __construct(private readonly ShopifyTransport $shopify, private readonly ShopifyOrderNormalizer $normalizer, private readonly ShipStationClientFactory $shipStation, private readonly OperationalDigestAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate, int $threshold): ReportResult
    {
        $checkedAt = CarbonImmutable::now($store->shopTimezone());
        $start = CarbonImmutable::parse($startDate, $store->shopTimezone())->startOfDay();
        $end = CarbonImmutable::parse($endDate, $store->shopTimezone())->addDay()->startOfDay();
        $coverage = [
            'shopify' => 'complete', 'shipstation' => 'unavailable',
            'manifests' => 'Manifest verification is unavailable with the current ShipStation V1 integration.',
            'billing_refunds' => 'Label refunds are not verified: shipment voiding does not confirm a billing refund.',
            'handover' => 'Shipping labels and ShipStation shipped status do not confirm physical carrier handover.',
        ];
        $orders = [];
        $ss = [];
        $pages = 0;
        try {
            $olderAccessVerified = true;
            if ($start->lt($checkedAt->subDays(60))) {
                $olderAccessVerified = false;
                try {
                    $scopes = $this->shopify->graphql($store, ShopifyQueries::get('OperationalDigestScopes'));
                    $granted = data_get($scopes, 'data.currentAppInstallation.accessScopes', []);
                    if (is_array($granted)) {
                        $olderAccessVerified = in_array('read_all_orders', array_column($granted, 'handle'), true);
                    }
                } catch (Throwable) {
                    $olderAccessVerified = false;
                }
            }
            $data = $this->shopify->paginateGraphql($store, ShopifyQueries::get('OperationalDigestOrders'), 'orders', ['search' => 'status:any created_at:>='.$start->toIso8601String().' created_at:<'.$end->toIso8601String()], 100);
            $pages = $data['pages'];
            $coverage['shopify'] = $data['truncated'] || ! $olderAccessVerified ? 'partial' : 'complete';
            if (! $olderAccessVerified) {
                $coverage['older_orders'] = 'Access to orders older than 60 days was not confirmed; read_all_orders is required.';
            }
            foreach ($data['edges'] as $edge) {
                if (! is_array($edge['node'] ?? null) || ! is_string($edge['node']['createdAt'] ?? null) || $edge['node']['createdAt'] === '') {
                    throw new UnexpectedValueException('Invalid Shopify order snapshot.');
                }
                CarbonImmutable::parse($edge['node']['createdAt']);
                $orders[] = $this->normalizer->normalize($edge['node']);
            }
        } catch (Throwable) {
            $orders = [];
            $coverage['shopify'] = 'unavailable';
        }
        if (! $store->missingShipStationCredentials() && ctype_digit((string) $store->store_number) && (int) $store->store_number > 0) {
            try {
                $all = $this->shipStation->forStore($store)?->fetchAllOrders($startDate, $endDate) ?? [];
                $ss = array_values(array_filter($all, fn (array $order): bool => (string) ($order['advancedOptions']['storeId'] ?? '') === (string) $store->store_number));
                $coverage['shipstation'] = count(array_filter($all, fn (array $order): bool => ! isset($order['advancedOptions']['storeId']))) > 0 ? 'partial' : 'complete';
            } catch (Throwable) {
                $coverage['shipstation'] = 'unavailable';
            }
        }
        $issues = [];
        foreach ($store->operationalIssues()->whereIn('status', IssueStatus::active())->orderBy('id')->cursor() as $issue) {
            $issues[] = ['id' => $issue->id, 'title' => $issue->title, 'order_number' => data_get($issue->payload, 'order_number', ''), 'due_at' => $issue->due_date?->toDateString(), 'priority' => $issue->priority->value];
        }
        $analysis = $this->analyzer->analyze($orders, $ss, $issues, $threshold, $checkedAt);
        $counts = $analysis['counts'];
        if ($coverage['shopify'] === 'unavailable') {
            $analysis['rows'][] = ['kind' => 'source_unavailable', 'reference' => 'shopify', 'order_number' => '', 'description' => 'Shopify snapshot unavailable; review API health', 'due_at' => null];
        }
        if ($coverage['shipstation'] === 'unavailable' && ! $store->missingShipStationCredentials() && ctype_digit((string) $store->store_number)) {
            $analysis['rows'][] = ['kind' => 'source_unavailable', 'reference' => 'shipstation', 'order_number' => '', 'description' => 'ShipStation reconciliation unavailable; review API health', 'due_at' => null];
        }
        if ($coverage['shopify'] === 'unavailable') {
            foreach (['paid_pending', 'sla_overdue', 'sla_due_soon'] as $key) {
                $counts[$key] = null;
            }
        }
        if ($coverage['shopify'] === 'unavailable' || $coverage['shipstation'] === 'unavailable') {
            $counts['sync_findings'] = null;
        }

        return new ReportResult(rows: $analysis['rows'], scanned: count($orders), pages: $pages, truncated: $coverage['shopify'] !== 'complete' || $coverage['shipstation'] !== 'complete', params: compact('startDate', 'endDate', 'threshold'), meta: ['counts' => $counts, 'coverage' => $coverage, 'checkedAt' => $checkedAt->toIso8601String(), 'timezone' => $store->shopTimezone(), 'orderWindow' => [$startDate, $endDate]]);
    }
}
