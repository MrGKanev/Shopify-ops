<?php

namespace App\Application\Operations;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;

class ManageShipStationMonitoring
{
    public function __construct(private readonly ShipStationClientFactory $clients) {}

    public function handle(Store $store): void
    {
        $subscriptions = $store->shipstation_monitoring_subscriptions ?? [];
        if (! $store->shipstation_monitoring_enabled && $subscriptions === [] && $store->shipstation_monitoring_token === null) {
            $store->forceFill(['shipstation_monitoring_status' => 'disabled'])->save();

            return;
        }
        $client = $this->clients->forStore($store) ?? throw new UnexpectedResponse('ShipStation credentials are required.');
        if (! $store->shipstation_monitoring_enabled) {
            $url = route('webhooks.shipstation', ['store' => $store, 'token' => $store->shipstation_monitoring_token]);
            foreach ($client->webhookSubscriptions() as $hook) {
                if (($hook['Url'] ?? '') === $url && is_numeric($hook['WebHookID'] ?? null) && (int) $hook['WebHookID'] > 0 && ! in_array((int) $hook['WebHookID'], $subscriptions, true)) {
                    $subscriptions['remote_'.(int) $hook['WebHookID']] = (int) $hook['WebHookID'];
                }
            }
            $store->forceFill(['shipstation_monitoring_subscriptions' => $subscriptions])->save();
            foreach ($subscriptions as $topic => $id) {
                $client->unsubscribeWebhook((int) $id);
                unset($subscriptions[$topic]);
                $store->forceFill(['shipstation_monitoring_subscriptions' => $subscriptions])->save();
            }
            $store->forceFill(['shipstation_monitoring_status' => 'disabled', 'shipstation_monitoring_token' => null])->save();

            return;
        }
        $url = route('webhooks.shipstation', ['store' => $store, 'token' => $store->shipstation_monitoring_token]);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new UnexpectedResponse('ShipStation monitoring requires an HTTPS callback.');
        }
        $remote = $client->webhookSubscriptions();
        foreach (['SHIP_NOTIFY', 'ORDER_NOTIFY'] as $topic) {
            $matches = array_values(array_filter($remote, fn (array $hook): bool => ($hook['Url'] ?? '') === $url && ($hook['HookType'] ?? '') === $topic));
            $id = $matches[0]['WebHookID'] ?? null;
            if ($id !== null && (! is_numeric($id) || (int) $id < 1)) {
                throw new UnexpectedResponse('ShipStation returned an invalid subscription identity.');
            }
            $subscriptions[$topic] = $id !== null ? (int) $id : $client->subscribeWebhook($url, $topic, (int) $store->store_number);
            $store->forceFill(['shipstation_monitoring_subscriptions' => $subscriptions])->save();
        }
        $store->forceFill(['shipstation_monitoring_status' => 'active'])->save();
    }
}
