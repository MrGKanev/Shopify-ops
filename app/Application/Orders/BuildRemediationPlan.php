<?php

namespace App\Application\Orders;

use App\Domain\Orders\RemediationUnavailable;
use App\Domain\Orders\ShipStationOrderUpdatePayload;
use App\Domain\Orders\ShipStationRelevantFingerprint;
use App\Domain\Reports\AddressCheckAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Integrations\Shopify\ShopifyQueries;
use App\Models\Store;

class BuildRemediationPlan
{
    public const array ACTIONS = [
        'sync_shipstation' => 'Update shipping data in ShipStation',
        'fulfill_tracking' => 'Create Shopify fulfillment with ShipStation tracking',
        'update_tracking' => 'Add ShipStation tracking to an existing Shopify fulfillment',
        'hold' => 'Hold fulfillment and add a review tag',
        'cancel_shipstation' => 'Cancel an active ShipStation order',
        'release_hold' => 'Release holds placed by Shopify Ops',
        'tag_shipstation' => 'Add a ShipStation tag',
        'refresh_import' => 'Refresh the ShipStation store import',
        'push_missing' => 'Push a still-missing order after import refresh',
        'add_tag' => 'Add a Shopify tag',
        'remove_tag' => 'Remove a Shopify tag',
    ];

    public function __construct(private readonly LoadOrderForRemediation $orders, private readonly ShopifyTransport $transport, private readonly AddressCheckAnalyzer $addresses) {}

    /** @param array<string, mixed> $options
     * @return array<string, mixed> */
    public function preview(Store $store, string $number, string $action, array $options = []): array
    {
        if (! isset(self::ACTIONS[$action])) {
            throw new RemediationUnavailable('Unknown remediation action.');
        }
        $number = ltrim(trim($number), '#');
        $order = $action === 'tag_shipstation' ? null : $this->orders->shopify($store, $number);
        $gid = $order === null ? null : 'gid://shopify/Order/'.$order['id'];
        $ss = null;
        $steps = [];
        $diff = [];
        $state = $order === null ? [] : ['id' => $order['id'], 'cancelled' => $order['cancelled_at'] ?? null, 'financial' => $order['financial_status'] ?? '', 'fulfillment' => $order['fulfillment_status'] ?? '', 'tags' => $order['tags'] ?? []];
        if (! in_array($action, ['add_tag', 'remove_tag'], true)) {
            $ss = $store->missingShipStationCredentials() && in_array($action, ['hold', 'release_hold'], true) ? null : ($order === null ? $this->orders->shipStationByNumber($store, $number) : $this->orders->shipStation($store, $order));
            $state['shipstation'] = $ss;
        }
        if (in_array($action, ['sync_shipstation', 'cancel_shipstation', 'tag_shipstation'], true)) {
            if ($ss === null) {
                throw new RemediationUnavailable('A matching ShipStation order is required.');
            }
            if ($action !== 'tag_shipstation' && ! in_array($ss['orderStatus'] ?? '', ['awaiting_payment', 'awaiting_shipment', 'on_hold'], true)) {
                throw new RemediationUnavailable('Shipped or cancelled ShipStation orders cannot be updated.');
            }
        }
        switch ($action) {
            case 'sync_shipstation':
                $this->requireShippable($order);
                if (($order['fulfillment_status'] ?? '') === 'partial' || ! empty($order['fulfillments']) || ($ss['advancedOptions']['mergedOrSplit'] ?? false)) {
                    throw new RemediationUnavailable('Partially fulfilled, merged or split orders require manual review before updating shipping data.');
                }
                $this->requireAddress($order);
                $expected = $this->orders->client($store)->buildOrderPayload($order);
                $diff = ShipStationRelevantFingerprint::diff($expected, $ss);
                if ($diff === []) {
                    throw new RemediationUnavailable('Shipping data already matches ShipStation.');
                }
                if (empty($ss['orderKey'])) {
                    throw new RemediationUnavailable('The existing ShipStation orderKey is required.');
                }
                $payload = ShipStationOrderUpdatePayload::build($ss);
                $existingItems = [];
                foreach ($ss['items'] ?? [] as $item) {
                    $existingItems[(string) ($item['lineItemKey'] ?? '')] = $item;
                }
                $payload['items'] = array_map(fn (array $item): array => array_replace($existingItems[$item['lineItemKey']] ?? [], $item), $expected['items']);
                $payload['shipTo'] = array_replace($ss['shipTo'] ?? [], $expected['shipTo']);
                $payload['requestedShippingService'] = $expected['requestedShippingService'];
                $steps[] = ['type' => 'ss_update', 'payload' => $payload];
                break;
            case 'cancel_shipstation':
                if (empty($order['cancelled_at']) && ($order['financial_status'] ?? '') !== 'refunded') {
                    throw new RemediationUnavailable('Only Shopify-cancelled or fully refunded orders can be cancelled in ShipStation.');
                }
                $payload = ShipStationOrderUpdatePayload::build($ss);
                if (empty($payload['orderKey'])) {
                    throw new RemediationUnavailable('The existing ShipStation orderKey is required.');
                }
                $payload['orderStatus'] = 'cancelled';
                $steps[] = ['type' => 'ss_update', 'payload' => $payload];
                break;
            case 'tag_shipstation':
                $tagId = (int) ($options['tag_id'] ?? 0);
                if ($tagId < 1 || in_array($tagId, $ss['tagIds'] ?? [], true)) {
                    throw new RemediationUnavailable('Select a ShipStation tag that is not already applied.');
                }
                $steps[] = ['type' => 'ss_tag', 'order_id' => (int) $ss['orderId'], 'tag_id' => $tagId];
                break;
            case 'hold':
            case 'release_hold':
                if ($action === 'release_hold') {
                    $this->requireShippable($order);
                } elseif (($order['fulfillment_status'] ?? '') === 'fulfilled') {
                    throw new RemediationUnavailable('An already fulfilled Shopify order cannot be held.');
                }
                $fulfillmentOrders = $this->fulfillmentOrders($store, $gid);
                foreach ($fulfillmentOrders as $fo) {
                    $holds = array_values(array_filter($fo['fulfillmentHolds'] ?? [], fn (array $hold): bool => ($hold['heldByRequestingApp'] ?? false) && ($hold['handle'] ?? '') === 'shopify-ops-review'));
                    if ($action === 'hold' && $holds === [] && in_array('HOLD', array_column($fo['supportedActions'] ?? [], 'action'), true)) {
                        $steps[] = $this->mutation('HoldRemediationFulfillment', 'fulfillmentOrderHold', ['id' => $fo['id'], 'hold' => ['reason' => 'OTHER', 'reasonNotes' => 'Operational review in Shopify Ops', 'handle' => 'shopify-ops-review']]);
                    }
                    if ($action === 'release_hold' && $holds !== [] && in_array('RELEASE_HOLD', array_column($fo['supportedActions'] ?? [], 'action'), true)) {
                        $steps[] = $this->mutation('ReleaseRemediationHold', 'fulfillmentOrderReleaseHold', ['id' => $fo['id'], 'holdIds' => array_column($holds, 'id')]);
                    }
                }
                $state['fulfillment_orders'] = $fulfillmentOrders;
                if ($ss !== null) {
                    $ssStatus = $ss['orderStatus'] ?? '';
                    if (! in_array($ssStatus, ['awaiting_shipment', 'on_hold'], true)) {
                        throw new RemediationUnavailable('ShipStation must be awaiting shipment or on hold.');
                    }
                    if ($action === 'hold' && $ssStatus !== 'on_hold') {
                        $until = (string) ($options['hold_until'] ?? now()->addDays(7)->toDateString());
                        $steps[] = ['type' => 'ss_hold', 'order_id' => (int) $ss['orderId'], 'hold_until' => $until];
                    }
                    if ($action === 'release_hold' && $ssStatus === 'on_hold') {
                        $heldByOps = $store->remediationRuns()->where('shopify_id', (string) $order['id'])->where('action', 'hold')->where('status', 'completed')->latest('id')->first();
                        $placedHold = null;
                        foreach ($heldByOps?->plan['steps'] ?? [] as $candidate) {
                            if (is_array($candidate) && ($candidate['type'] ?? '') === 'ss_hold') {
                                $placedHold = $candidate;
                                break;
                            }
                        }
                        if ($placedHold === null || substr((string) ($ss['holdUntilDate'] ?? ''), 0, 10) !== $placedHold['hold_until']) {
                            throw new RemediationUnavailable('ShipStation hold was not recorded as placed by Shopify Ops. Release it in ShipStation.');
                        }
                        $steps[] = ['type' => 'ss_restore', 'order_id' => (int) $ss['orderId']];
                    }
                }
                if ($action === 'hold' && ! in_array('ops-review', $order['tags'] ?? [], true)) {
                    $steps[] = $this->mutation('AddRemediationTags', 'tagsAdd', ['id' => $gid, 'tags' => ['ops-review']]);
                }
                break;
            case 'add_tag':
            case 'remove_tag':
                $tag = trim((string) ($options['tag'] ?? ''));
                if ($tag === '') {
                    throw new RemediationUnavailable('A Shopify tag is required.');
                }
                $hasTag = in_array($tag, $order['tags'] ?? [], true);
                if (($action === 'add_tag') === $hasTag) {
                    throw new RemediationUnavailable('The tag is already in the requested state.');
                }
                $steps[] = $this->mutation($action === 'add_tag' ? 'AddRemediationTags' : 'RemoveRemediationTags', $action === 'add_tag' ? 'tagsAdd' : 'tagsRemove', ['id' => $gid, 'tags' => [$tag]]);
                break;
            case 'refresh_import':
            case 'push_missing':
                $this->requireShippable($order);
                if ($ss !== null) {
                    throw new RemediationUnavailable('The order already exists in ShipStation.');
                }
                if (! ctype_digit((string) $store->store_number) || (int) $store->store_number < 1) {
                    throw new RemediationUnavailable('Configure the ShipStation store number first.');
                }
                if ($action === 'refresh_import') {
                    $steps[] = ['type' => 'ss_refresh', 'store_id' => (int) $store->store_number];
                } else {
                    if (! $store->remediationRuns()->where('action', 'refresh_import')->where('status', 'completed')->where('finished_at', '>=', now()->subMinutes(30))->exists()) {
                        throw new RemediationUnavailable('Refresh the store import first, wait for it to complete, then review the missing order again.');
                    }
                    $this->requireAddress($order);
                    $payload = $this->orders->client($store)->buildOrderPayload($order);
                    $payload['advancedOptions'] = ['storeId' => (int) $store->store_number];
                    $steps[] = ['type' => 'ss_create', 'payload' => $payload];
                }
                break;
            case 'fulfill_tracking':
            case 'update_tracking':
                $this->requireShippable($order, allowFulfilled: $action === 'update_tracking');
                if ($ss === null || ($ss['orderStatus'] ?? '') !== 'shipped') {
                    throw new RemediationUnavailable('A shipped ShipStation order is required.');
                }
                $shipments = array_values(array_filter($this->orders->client($store)->getOrderShipments($number, includeItems: true), fn (array $shipment): bool => (string) ($shipment['orderId'] ?? '') === (string) $ss['orderId'] && ! ($shipment['voided'] ?? false)));
                if (count($shipments) !== 1 || trim((string) ($shipments[0]['trackingNumber'] ?? '')) === '') {
                    throw new RemediationUnavailable('Exactly one non-voided shipment with tracking is required. Split shipments require manual review.');
                }
                $shipment = $shipments[0];
                $state['shipment'] = $shipment;
                $tracking = ['number' => $shipment['trackingNumber']];
                $fulfillments = array_values(array_filter($order['fulfillments'] ?? [], fn (array $fulfillment): bool => ($fulfillment['status'] ?? '') === 'success'));
                $state['fulfillments'] = $fulfillments;
                if ($action === 'update_tracking') {
                    if (count($fulfillments) !== 1 || ! empty($fulfillments[0]['tracking_number']) || ! empty($fulfillments[0]['tracking_numbers'])) {
                        throw new RemediationUnavailable('Exactly one successful Shopify fulfillment without tracking is required.');
                    }
                    $this->requireSameItems($shipment['shipmentItems'] ?? [], $fulfillments[0]['line_items'] ?? []);
                    $id = $fulfillments[0]['admin_graphql_api_id'] ?? 'gid://shopify/Fulfillment/'.$fulfillments[0]['id'];
                    $steps[] = $this->mutation('UpdateRemediationTracking', 'fulfillmentTrackingInfoUpdate', ['id' => $id, 'tracking' => $tracking]);
                } else {
                    if ($fulfillments !== []) {
                        throw new RemediationUnavailable('Existing partial fulfillments require manual review to avoid fulfilling items twice.');
                    }
                    $fulfillmentOrders = $this->fulfillmentOrders($store, $gid);
                    $state['fulfillment_orders'] = $fulfillmentOrders;
                    $candidates = array_values(array_filter($fulfillmentOrders, fn (array $fo): bool => in_array('CREATE_FULFILLMENT', array_column($fo['supportedActions'] ?? [], 'action'), true)));
                    if (count($candidates) !== 1) {
                        throw new RemediationUnavailable('Exactly one fulfillable Shopify fulfillment order is required.');
                    }
                    $fo = $candidates[0];
                    $lines = array_values(array_filter($fo['lineItems']['nodes'], fn (array $line): bool => (int) $line['remainingQuantity'] > 0));
                    $this->requireSameItems($shipment['shipmentItems'] ?? [], array_map(fn (array $line): array => ['sku' => $line['lineItem']['sku'], 'quantity' => (int) $line['remainingQuantity']], $lines));
                    $steps[] = $this->mutation('CreateRemediationFulfillment', 'fulfillmentCreate', ['fulfillment' => [
                        'lineItemsByFulfillmentOrder' => [['fulfillmentOrderId' => $fo['id'], 'fulfillmentOrderLineItems' => array_map(fn (array $line): array => ['id' => $line['id'], 'quantity' => (int) $line['remainingQuantity']], $lines)]],
                        'trackingInfo' => $tracking, 'notifyCustomer' => false,
                    ]]);
                }
                break;
        }
        if ($steps === []) {
            throw new RemediationUnavailable('No eligible changes remain for this action.');
        }
        foreach ($steps as &$step) {
            $step['description'] = $this->description($step);
        }
        unset($step);
        $plan = ['order_number' => $number, 'shopify_id' => $order === null ? null : (string) $order['id'], 'shipstation_id' => $ss['orderId'] ?? null, 'action' => $action, 'options' => $options, 'steps' => $steps, 'diff' => $diff];
        $plan['fingerprint'] = hash('sha256', json_encode([$plan, $state], JSON_THROW_ON_ERROR));

        return $plan;
    }

    /** @param array<string, mixed> $step */
    private function description(array $step): string
    {
        return match ($step['type']) {
            'ss_update' => ($step['payload']['orderStatus'] ?? '') === 'cancelled' ? 'Cancel this active order in ShipStation.' : 'Update the shipping address, items and requested service in ShipStation.',
            'ss_create' => 'Create the missing order in ShipStation.',
            'ss_hold' => 'Hold this ShipStation order until the selected date.',
            'ss_restore' => 'Release the recorded Shopify Ops hold in ShipStation.',
            'ss_tag' => 'Add the selected tag to this ShipStation order.',
            'ss_refresh' => 'Request a fresh store import in ShipStation. This does not push an order.',
            'shopify' => match ($step['field']) {
                'tagsAdd' => 'Add the selected Shopify tag.',
                'tagsRemove' => 'Remove the selected Shopify tag.',
                'fulfillmentOrderHold' => 'Place a Shopify fulfillment hold for operational review.',
                'fulfillmentOrderReleaseHold' => 'Release only the selected Shopify Ops fulfillment holds.',
                'fulfillmentCreate' => 'Fulfill the verified shipped items in Shopify and attach tracking. Customer notifications are disabled.',
                'fulfillmentTrackingInfoUpdate' => 'Attach tracking to the existing Shopify fulfillment. Customer notifications are disabled.',
                default => throw new RemediationUnavailable('Unknown Shopify remediation step.'),
            },
            default => throw new RemediationUnavailable('Unknown remediation step.'),
        };
    }

    /** @param array<string, mixed> $variables
     * @return array<string, mixed> */
    private function mutation(string $operation, string $field, array $variables): array
    {
        return ['type' => 'shopify', 'operation' => $operation, 'field' => $field, 'variables' => $variables];
    }

    /** @return list<array<string, mixed>> */
    private function fulfillmentOrders(Store $store, string $id): array
    {
        $result = $this->transport->graphql($store, ShopifyQueries::get('RemediationFulfillmentOrders'), ['id' => $id]);
        $connection = $result['data']['order']['fulfillmentOrders'] ?? null;
        if (! is_array($connection) || ! is_array($connection['nodes'] ?? null) || ($connection['pageInfo']['hasNextPage'] ?? true)) {
            throw new RemediationUnavailable('Complete Shopify fulfillment data is required.');
        }
        $orders = [];
        foreach ($connection['nodes'] as $fo) {
            if (! is_array($fo) || ! is_array($fo['lineItems']['nodes'] ?? null) || ($fo['lineItems']['pageInfo']['hasNextPage'] ?? true)) {
                throw new RemediationUnavailable('Complete Shopify fulfillment items are required.');
            }
            $orders[] = $fo;
        }

        return $orders;
    }

    /** @param array<string, mixed> $order */
    private function requireShippable(array $order, bool $allowFulfilled = false): void
    {
        if (! empty($order['cancelled_at']) || ($order['financial_status'] ?? '') !== 'paid' || (! $allowFulfilled && ($order['fulfillment_status'] ?? '') === 'fulfilled')) {
            throw new RemediationUnavailable('A paid, non-cancelled Shopify order with eligible fulfillment status is required.');
        }
    }

    /** @param array<string, mixed> $order */
    private function requireAddress(array $order): void
    {
        foreach ($this->addresses->check($order['shipping_address'] ?? $order['billing_address'] ?? null, $order) as $issue) {
            if ($issue['level'] === 'critical') {
                throw new RemediationUnavailable('Fix the critical shipping address problems before updating ShipStation.');
            }
        }
    }

    /** @param list<array<string, mixed>> $shipped
     * @param list<array<string, mixed>> $expected */
    private function requireSameItems(array $shipped, array $expected): void
    {
        $quantities = static function (array $items): array {
            $result = [];
            foreach ($items as $item) {
                $sku = trim((string) ($item['sku'] ?? ''));
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($sku === '' || $quantity < 1 || isset($result[$sku])) {
                    throw new RemediationUnavailable('Unique, non-empty SKUs and positive quantities are required for tracking remediation.');
                }
                $result[$sku] = $quantity;
            }
            ksort($result);

            return $result;
        };
        if ($shipped === [] || $quantities($shipped) !== $quantities($expected)) {
            throw new RemediationUnavailable('Shipped items do not exactly match the Shopify fulfillment quantities.');
        }
    }
}
