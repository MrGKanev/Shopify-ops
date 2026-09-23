<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Store;

trait BuildsNotificationRulesView
{
    /** @return array<string, mixed> */
    private function notificationRulesViewData(Store $store, string $activeTab): array
    {
        $emailRules = $store->resolvedEmailRules();
        foreach (array_keys(config('tool-catalog')) as $tool) {
            $emailRules[$tool] ??= ['mode' => 'off', 'threshold' => $tool === 'run_audit' ? 0 : 1, 'include_zero' => false, 'email' => ''];
        }

        return [
            'activeTab' => $activeTab,
            'slackRules' => $store->resolvedSlackRules(),
            'slackConfigured' => trim((string) config('services.slack.notifications.webhook_url')) !== '',
            'discordRules' => $store->resolvedDiscordRules(),
            'discordConfigured' => trim((string) config('services.discord.notifications.webhook_url')) !== '',
            'rules' => $emailRules,
            'catalog' => config('tool-catalog'),
            'defaultAlertEmail' => $store->default_alert_email,
        ];
    }
}
