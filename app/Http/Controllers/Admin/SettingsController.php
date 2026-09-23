<?php

namespace App\Http\Controllers\Admin;

use App\Application\Auth\LoginThrottle;
use App\Application\Health\SendTestDiscord;
use App\Application\Health\SendTestEmail;
use App\Application\Health\SendTestSlack;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __invoke(Request $request, SendTestEmail $email, SendTestSlack $slack, SendTestDiscord $discord, LoginThrottle $throttle): View
    {
        $store = $this->resolveStore($request);
        $slackRules = $store->resolvedSlackRules();
        $discordRules = $store->resolvedDiscordRules();

        return view('admin.settings', [
            'store' => $store,
            'connections' => [
                'Shopify' => trim((string) $store->shopify_store) !== '' && trim((string) $store->shopify_access_token) !== '',
                'ShipStation' => trim((string) $store->shipstation_api_key) !== '' && trim((string) $store->shipstation_api_secret) !== '',
            ],
            'notifications' => [
                'Slack' => ['configured' => $slack->configuration()['configured'], 'rules' => (int) $slackRules['audit_enabled'] + (int) $slackRules['scan_enabled']],
                'Discord' => ['configured' => $discord->configuration()['configured'], 'rules' => (int) $discordRules['audit_enabled'] + (int) $discordRules['scan_enabled']],
                'Email' => ['configured' => $email->configuration()['configured'], 'rules' => count(array_filter($store->resolvedEmailRules(), fn (array $rule): bool => $rule['mode'] !== 'off'))],
            ],
            'bannedIpCount' => $throttle->bannedIps()->count(),
        ]);
    }
}
