<?php

namespace App\Http\Controllers;

use App\Application\Operations\ReceiveShipStationEvent;
use App\Integrations\ShipStation\WebhookResourceUrl;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class ShipStationWebhookController extends Controller
{
    public function __invoke(Request $request, Store $store, string $token, WebhookResourceUrl $resources, ReceiveShipStationEvent $events): Response
    {
        abort_unless($store->shipstation_monitoring_enabled && is_string($store->shipstation_monitoring_token) && hash_equals($store->shipstation_monitoring_token, $token), 404);
        abort_unless($store->shipStationMonitoringCanQueue(), 503);
        abort_if(strlen($request->getContent()) > 8192, 413);
        $payload = $request->validate(['resource_type' => ['required', 'string', 'in:SHIP_NOTIFY,ORDER_NOTIFY'], 'resource_url' => ['required', 'string', 'max:2048']]);
        try {
            $resource = $resources->parse($payload['resource_url'], $payload['resource_type'], (int) $store->store_number);
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid ShipStation resource.');
        }
        $events->handle($store, $payload['resource_type'], json_encode($resource, JSON_THROW_ON_ERROR), ['resource_url' => $payload['resource_url']]);

        return response()->noContent();
    }
}
