<?php

namespace App\Application\Reports;

use App\Domain\Reports\AuditOrderAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Notifications\AuditDiscordNotification;
use App\Notifications\AuditSlackNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Throwable;

class RunAudit
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyAdminGateway $shopify, private readonly AuditOrderAnalyzer $analyzer, private readonly RecordRun $runs) {}

    public function handle(Store $store, string $start, string $end): AuditResult
    {
        $started = microtime(true);

        try {
            $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
            $shopify = $this->shopify->itemMismatchCandidates($store, $start, $end);
            $onHold = $this->shopify->onHoldFulfillmentCandidates($store, $start, $end);
            $shipstation = $client->fetchAllOrders($start, CarbonImmutable::parse($end)->addDays(7)->toDateString());
            $ignored = [];
            foreach ($store->ignoredOrders()->get() as $row) {
                $ignored[$row->order_number] = ['reason' => $row->reason, 'ignored_at' => (string) $row->ignored_at];
            }
            $onHoldIds = [];
            foreach ($onHold['fulfillment_orders'] as $fulfillmentOrder) {
                $orderId = $fulfillmentOrder['order']['legacyResourceId'] ?? null;
                if (is_scalar($orderId)) {
                    $onHoldIds[(string) $orderId] = true;
                }
            }
            $result = $this->analyzer->analyze($shopify['orders'], $shipstation, $ignored, $onHoldIds);
            $store->auditSnapshots()->updateOrCreate(['tool' => 'run_audit', 'report_date' => today()->toDateString()], ['start_date' => $start, 'end_date' => $end, 'rows_found' => count($result['missing']), 'result' => ['missing' => $result['missing'], 'found' => count($result['found']), 'skipped' => count($result['skipped']), 'ignored' => count($result['ignored']), 'shopify_total' => count($shopify['orders']), 'shipstation_total' => count($shipstation), 'truncated' => $shopify['truncated'] || $onHold['truncated']]]);
            $this->runs->handle($store, ['tool' => 'run_audit', 'status' => $result['missing'] ? 'issues_found' : 'ok', 'start_date' => $start, 'end_date' => $end, 'duration_seconds' => round(microtime(true) - $started, 3), 'scanned' => count($shopify['orders']), 'rows_found' => count($result['missing']), 'meta' => ['shipstation_total' => count($shipstation), 'found' => count($result['found']), 'skipped' => count($result['skipped']), 'ignored' => count($result['ignored'])]]);
            $rules = $store->resolvedSlackRules();
            $missing = count($result['missing']);
            if ($rules['audit_enabled'] && $missing >= $rules['audit_min_missing'] && ($missing > 0 || $rules['include_zero_audit']) && trim((string) config('services.slack.notifications.webhook_url')) !== '') {
                Notification::route('slack', config('services.slack.notifications.webhook_url'))->notify(new AuditSlackNotification($store->label, $missing, "{$start} → {$end}", $rules['mentions']));
            }
            $discordRules = $store->resolvedDiscordRules();
            if ($discordRules['audit_enabled'] && $missing >= $discordRules['audit_min_missing'] && ($missing > 0 || $discordRules['include_zero_audit']) && trim((string) config('services.discord.notifications.webhook_url')) !== '') {
                Notification::route('discord', config('services.discord.notifications.webhook_url'))->notify(new AuditDiscordNotification($store->label, $missing, "{$start} → {$end}"));
            }

            return new AuditResult($start, $end, $result['missing'], count($result['found']), count($result['skipped']), count($result['ignored']), count($shopify['orders']), count($shipstation), $shopify['truncated'] || $onHold['truncated']);
        } catch (Throwable $exception) {
            $this->runs->handle($store, ['tool' => 'run_audit', 'status' => 'error', 'start_date' => $start, 'end_date' => $end, 'duration_seconds' => round(microtime(true) - $started, 3), 'error' => 'Audit failed.']);
            throw $exception;
        }
    }
}
