<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileShipStationMonitoring;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShipStationMonitoringController extends Controller
{
    public function __invoke(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $data['enabled'];
        Cache::lock('shipstation-monitoring:'.$store->id, 300)->block(5, function () use ($store, $enabled, $request): void {
            $store->refresh();
            if ($enabled && ($store->missingShopifyCredentials() || $store->missingShipStationCredentials() || ! ctype_digit((string) $store->store_number) || (int) $store->store_number < 1 || ! $store->shipStationMonitoringCanQueue() || parse_url(route('webhooks.shipstation', ['store' => $store, 'token' => 'check']), PHP_URL_SCHEME) !== 'https')) {
                throw ValidationException::withMessages(['shipstation_monitoring' => __('Monitoring requires Shopify and ShipStation V1 credentials, a positive ShipStation store number, an HTTPS callback and a background queue.')]);
            }
            if ($enabled && ! $store->shipstation_monitoring_enabled) {
                if (! empty($store->shipstation_monitoring_subscriptions) || $store->shipstation_monitoring_token !== null) {
                    throw ValidationException::withMessages(['shipstation_monitoring' => __('Remove the previous subscriptions before enabling monitoring again.')]);
                }
                $store->forceFill(['shipstation_monitoring_token' => Str::random(64), 'shipstation_monitoring_started_at' => now(), 'shipstation_monitoring_checked_at' => null])->save();
            }
            $store->forceFill(['shipstation_monitoring_enabled' => $enabled, 'shipstation_monitoring_status' => $enabled ? 'pending' : 'stopping'])->save();
            if (! $enabled) {
                $store->shipStationEvents()->whereIn('status', ['received', 'failed'])->update(['status' => 'skipped', 'processed_at' => now()]);
            }
            activity('administration')->causedBy($request->user())->performedOn($store)->withProperties(['enabled' => $enabled])->log('ShipStation monitoring changed');
        });
        ReconcileShipStationMonitoring::dispatch($store->id);

        return back()->with('status', $enabled ? __('ShipStation monitoring enabled. Subscription setup is queued.') : __('ShipStation monitoring disabled. Subscription removal is queued.'));
    }
}
