<?php

namespace App\Application\Orders;

use App\Domain\Orders\OrderRiskScorer;
use App\Domain\Orders\OrderTimelineBuilder;
use App\Domain\Orders\OrderTimelineRiskAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class LoadOrderTimeline
{
    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationClients,
        private readonly OrderTimelineBuilder $timelineBuilder,
        private readonly OrderTimelineRiskAnalyzer $riskAnalyzer,
        private readonly OrderRiskScorer $riskScorer,
    ) {}

    public function handle(Store $store, string $orderNumber): OrderTimelineResult
    {
        $shopifyOrders = $this->shopify->findByOrderNumber($store, $orderNumber);
        $shopifyMatchCount = count($shopifyOrders);
        $shipStationConfigured = trim((string) $store->shipstation_api_key) !== ''
            && trim((string) $store->shipstation_api_secret) !== '';

        if ($shopifyMatchCount !== 1) {
            return new OrderTimelineResult(
                orderNumber: $orderNumber,
                state: $shopifyMatchCount === 0 ? 'not_found' : 'ambiguous',
                shopifyMatchCount: $shopifyMatchCount,
                order: null,
                shipStationOrders: [],
                shipStationShipments: [],
                shipStationConfigured: $shipStationConfigured,
                timeline: [],
                operationalRisks: [],
                timeToShip: null,
                riskScore: null,
            );
        }

        $order = $shopifyOrders[0];
        $orderId = trim((string) ($order['admin_graphql_api_id'] ?? $order['id'] ?? ''));
        $events = $this->shopify->getOrderEvents($store, $orderId);
        $shipStation = $this->shipStationClients->forStore($store);
        $shipStationOrders = $shipStation?->findByOrderNumber($orderNumber) ?? [];
        $shipStationShipments = $shipStation?->getOrderShipments($orderNumber) ?? [];
        $shopifyIds = array_values(array_unique(array_filter([
            trim((string) ($order['id'] ?? '')),
            preg_match('~/([0-9]+)$~', $orderId, $matches) === 1 ? $matches[1] : null,
        ])));
        $integrationEvents = $store->webhookEvents()
            ->whereIn('subject_id', $shopifyIds)
            ->orderBy('occurred_at')
            ->get(['topic', 'status', 'error_category', 'occurred_at', 'processed_at'])
            ->map(fn ($event): array => [
                'timestamp' => ($event->processed_at ?? $event->occurred_at)->toIso8601String(),
                'type' => 'shopify_webhook',
                'source' => 'integration',
                'title' => 'Shopify webhook: '.$event->topic,
                'detail' => 'Status: '.$event->status.($event->error_category ? ' · '.$event->error_category : ''),
            ])->all();
        $integrationEvents = [...$integrationEvents, ...$store->pushLogs()
            ->whereIn('order_number', [$orderNumber, '#'.$orderNumber])
            ->orderBy('pushed_at')
            ->get(['shipstation_order_id', 'pushed_at', 'status', 'error_category'])
            ->map(fn ($push): array => [
                'timestamp' => $push->pushed_at->toIso8601String(),
                'type' => 'shipstation_push',
                'source' => 'integration',
                'title' => $push->status === 'failed' ? 'ShipStation push failed' : 'Order pushed to ShipStation',
                'detail' => $push->status === 'failed'
                    ? 'Failure: '.$push->error_category
                    : ($push->shipstation_order_id ? 'ShipStation order ID '.$push->shipstation_order_id : ''),
                'url' => $push->status === 'success' && is_numeric($push->shipstation_order_id) ? 'https://app.shipstation.com/#!/orders/order-details/'.rawurlencode((string) $push->shipstation_order_id) : '',
            ])->all()];

        return new OrderTimelineResult(
            orderNumber: $orderNumber,
            state: 'ready',
            shopifyMatchCount: 1,
            order: $order,
            shipStationOrders: $shipStationOrders,
            shipStationShipments: $shipStationShipments,
            shipStationConfigured: $shipStation !== null,
            timeline: $this->timelineBuilder->build($order, $events, $shipStationOrders, $shipStationShipments, $integrationEvents),
            operationalRisks: $this->riskAnalyzer->analyze($order, $shipStationOrders),
            timeToShip: $this->riskAnalyzer->timeToShip($order),
            riskScore: $this->riskScorer->score($order),
        );
    }
}
