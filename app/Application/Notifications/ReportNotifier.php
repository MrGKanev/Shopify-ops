<?php

namespace App\Application\Notifications;

use App\Models\Store;
use App\Notifications\AuditFinishedNotification;
use App\Notifications\ReportEmailNotification;
use App\Notifications\ScanFinishedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Decides which channels a finished report or audit is announced on, using the store's Slack,
 * Discord, and email rules, and queues the matching notifications.
 */
class ReportNotifier
{
    /**
     * Announce a finished scan report on Slack and Discord when its row count meets the store's scan rules.
     */
    public function scanFinished(Store $store, string $tool, int $rows, float $durationSeconds): void
    {
        $slackRules = $store->resolvedSlackRules();
        $routes = $this->chatRoutes(
            $this->scanRuleMatches($slackRules, $rows),
            $this->scanRuleMatches($store->resolvedDiscordRules(), $rows),
        );

        if ($routes !== []) {
            $this->onDemand($routes)->notify(new ScanFinishedNotification($store->label, $tool, $rows, $slackRules['mentions'], $durationSeconds));
        }
    }

    /**
     * Announce a finished Run Audit on Slack and Discord when the missing-order count meets the store's audit rules.
     *
     * @param  array{found: int, skipped: int, ignored: int, shipstation_total: int}  $counts
     * @param  list<array{name: string, total: float}>  $missingOrders
     */
    public function auditFinished(Store $store, string $period, array $missingOrders, array $counts, float $durationSeconds): void
    {
        $missing = count($missingOrders);
        $slackRules = $store->resolvedSlackRules();
        $routes = $this->chatRoutes(
            $this->auditRuleMatches($slackRules, $missing),
            $this->auditRuleMatches($store->resolvedDiscordRules(), $missing),
        );

        if ($routes !== []) {
            $this->onDemand($routes)->notify(new AuditFinishedNotification($store->label, $missing, $period, $slackRules['mentions'], $counts['found'], $counts['skipped'], $counts['ignored'], $counts['shipstation_total'], $durationSeconds, $missingOrders));
        }
    }

    /**
     * Email a finished report immediately when the tool's email rule is in immediate mode and its threshold is met.
     *
     * @param  array{headers: list<string>, rows: list<list<bool|float|int|string|null>>}|null  $attachment
     */
    public function emailImmediately(Store $store, string $tool, int $rows, ?string $startDate, ?string $endDate, ?array $attachment = null): void
    {
        $rule = $store->resolvedEmailRules()[$tool] ?? null;
        if ($rule === null || $rule['mode'] !== 'immediate' || ! $this->emailRuleMatches($rule, $rows)) {
            return;
        }

        $recipient = $this->emailRecipient($store, $rule);
        if ($recipient !== '') {
            Notification::route('mail', $recipient)->notify(new ReportEmailNotification($store->label, $tool, $rows, $startDate, $endDate, $attachment['headers'] ?? null, $attachment['rows'] ?? null));
        }
    }

    /**
     * @param  array{mode: string, threshold: int, include_zero: bool, email: string}  $rule
     */
    public function emailRuleMatches(array $rule, int $rows): bool
    {
        return $rows >= $rule['threshold'] && ($rows > 0 || $rule['include_zero']);
    }

    /**
     * The tool's own recipient, falling back to the store's default alert address.
     *
     * @param  array{mode: string, threshold: int, include_zero: bool, email: string}  $rule
     */
    public function emailRecipient(Store $store, array $rule): string
    {
        return $rule['email'] !== '' ? $rule['email'] : trim((string) ($store->default_alert_email ?? ''));
    }

    /** @param  array{scan_enabled: bool, scan_min_rows: int}  $rules */
    private function scanRuleMatches(array $rules, int $rows): bool
    {
        return $rules['scan_enabled'] && $rows >= $rules['scan_min_rows'];
    }

    /** @param  array{audit_enabled: bool, audit_min_missing: int, include_zero_audit: bool}  $rules */
    private function auditRuleMatches(array $rules, int $missing): bool
    {
        return $rules['audit_enabled'] && $missing >= $rules['audit_min_missing'] && ($missing > 0 || $rules['include_zero_audit']);
    }

    /**
     * Webhook routes for the chat channels whose rules matched and that are configured.
     *
     * @return array<'slack'|'discord', string>
     */
    private function chatRoutes(bool $slack, bool $discord): array
    {
        return array_filter([
            'slack' => $slack ? $this->webhookUrl('slack') : null,
            'discord' => $discord ? $this->webhookUrl('discord') : null,
        ]);
    }

    /** @param  array<string, string>  $routes */
    private function onDemand(array $routes): AnonymousNotifiable
    {
        $notifiable = new AnonymousNotifiable;
        foreach ($routes as $channel => $route) {
            $notifiable->route($channel, $route);
        }

        return $notifiable;
    }

    private function webhookUrl(string $service): ?string
    {
        $url = trim((string) config("services.{$service}.notifications.webhook_url"));

        return $url === '' ? null : $url;
    }
}
