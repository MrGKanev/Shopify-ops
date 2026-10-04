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
        $slackRules = $store->slack_rules;
        $routes = $this->chatRoutes(
            $slackRules->scan->matches($rows),
            $store->discord_rules->scan->matches($rows),
        );

        if ($routes !== []) {
            $this->onDemand($routes)->notify(new ScanFinishedNotification($store->label, $tool, $rows, $slackRules->mentions, $durationSeconds));
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
        $slackRules = $store->slack_rules;
        $routes = $this->chatRoutes(
            $slackRules->audit->matches($missing),
            $store->discord_rules->audit->matches($missing),
        );

        if ($routes !== []) {
            $this->onDemand($routes)->notify(new AuditFinishedNotification($store->label, $missing, $period, $slackRules->mentions, $counts['found'], $counts['skipped'], $counts['ignored'], $counts['shipstation_total'], $durationSeconds, $missingOrders));
        }
    }

    /**
     * Email a finished report immediately when the tool's email rule is in immediate mode and its threshold is met.
     *
     * @param  array{headers: list<string>, rows: list<list<bool|float|int|string|null>>}|null  $attachment
     */
    public function emailImmediately(Store $store, string $tool, int $rows, ?string $startDate, ?string $endDate, ?array $attachment = null): void
    {
        $rule = $store->email_rules->forTool($tool);
        if ($rule === null || $rule->mode !== 'immediate' || ! $this->emailRuleMatches($rule, $rows)) {
            return;
        }

        $recipient = $this->emailRecipient($store, $rule);
        if ($recipient !== '') {
            Notification::route('mail', $recipient)->notify(new ReportEmailNotification($store->label, $tool, $rows, $startDate, $endDate, $attachment['headers'] ?? null, $attachment['rows'] ?? null));
        }
    }

    public function emailRuleMatches(EmailRule $rule, int $rows): bool
    {
        return $rule->matches($rows);
    }

    public function emailRecipient(Store $store, EmailRule $rule): string
    {
        return $rule->recipient($store->default_alert_email);
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
