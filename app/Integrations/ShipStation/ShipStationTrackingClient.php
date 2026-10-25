<?php

namespace App\Integrations\ShipStation;

use App\Application\Reports\TrackingReadBudget;
use App\Integrations\Concerns\ConfiguresIntegrationRequests;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Models\Store;
use LogicException;

class ShipStationTrackingClient
{
    use ConfiguresIntegrationRequests;

    public function __construct(private readonly TrackingReadBudget $budget) {}

    /** @return array<string, mixed> */
    public function track(Store $store, string $carrier, string $tracking, bool $requireVerifiedAccess = false): array
    {
        $current = Store::query()->select(['id', 'shipstation_api_key', 'shipstation_api_secret', 'shipstation_v2_api_key', 'store_number', 'movement_policy'])->find($store->id);
        if ($current === null || (string) $current->shipstation_api_key !== (string) $store->shipstation_api_key || (string) $current->shipstation_api_secret !== (string) $store->shipstation_api_secret || (string) $current->store_number !== (string) $store->store_number || (string) $current->shipstation_v2_api_key !== (string) $store->shipstation_v2_api_key) {
            throw new LogicException('The tracking integration changed during this check. Start a new check.');
        }
        if ($requireVerifiedAccess && ! $current->movementPolicy()['access_verified']) {
            throw new LogicException('Tracking access verification was revoked.');
        }
        if (trim((string) $current->shipstation_v2_api_key) === '') {
            throw new LogicException('A ShipStation V2 tracking key is required.');
        }
        $this->budget->claim($current);
        $response = $this->integrationRequest('https://api.shipstation.com/v2')
            ->withoutRedirecting()
            ->withHeader('API-Key', $current->shipstation_v2_api_key)
            ->get('/tracking', ['carrier_code' => $carrier, 'tracking_number' => $tracking]);
        $this->checkedResponse($response);
        $data = $response->json();
        $carriers = $carrier === 'stamps_com' ? ['stamps_com', 'usps'] : [$carrier];
        if (! is_array($data) || ($data['tracking_number'] ?? null) !== $tracking || ! in_array($data['carrier_code'] ?? null, $carriers, true) || ! is_string($data['status_code'] ?? null) || ! is_array($data['events'] ?? null) || ! array_is_list($data['events']) || count($data['events']) > 1000) {
            throw new UnexpectedResponse('ShipStation tracking identity or event coverage could not be confirmed.');
        }

        return $data;
    }
}
