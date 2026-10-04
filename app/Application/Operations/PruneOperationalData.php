<?php

namespace App\Application\Operations;

use App\Models\LoginAttempt;
use App\Models\NotificationDelivery;
use App\Models\RunLog;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * Removes operational history that is no longer needed and strips customer data from old webhook payloads.
 *
 * Webhook events keep their row (topic, status, timestamps) so delivery history stays auditable;
 * only the payload, which holds names, emails, addresses and phone numbers, is cleared.
 */
class PruneOperationalData
{
    /**
     * @param  array{webhook_payload_days: int, login_attempt_days: int, notification_delivery_days: int, run_log_days: int}  $retentionDays
     * @return array{webhook_payloads: int, login_attempts: int, notification_deliveries: int, run_logs: int}
     */
    public function handle(array $retentionDays, bool $dryRun = false): array
    {
        $queries = [
            'webhook_payloads' => WebhookEvent::query()
                ->whereNull('payload_pruned_at')
                ->where('occurred_at', '<', now()->subDays($retentionDays['webhook_payload_days'])),
            'login_attempts' => LoginAttempt::query()
                ->where('updated_at', '<', now()->subDays($retentionDays['login_attempt_days']))
                ->where(fn (Builder $query) => $query->whereNull('banned_until')->orWhere('banned_until', '<', now())),
            'notification_deliveries' => NotificationDelivery::query()
                ->where('created_at', '<', now()->subDays($retentionDays['notification_delivery_days'])),
            'run_logs' => RunLog::query()
                ->where('created_at', '<', now()->subDays($retentionDays['run_log_days'])),
        ];

        if ($dryRun) {
            return array_map(fn (Builder $query): int => $query->count(), $queries);
        }

        return [
            'webhook_payloads' => $queries['webhook_payloads']->update([
                'payload' => (new WebhookEvent)->forceFill(['payload' => []])->getAttributes()['payload'],
                'payload_pruned_at' => now(),
            ]),
            'login_attempts' => $queries['login_attempts']->delete(),
            'notification_deliveries' => $queries['notification_deliveries']->delete(),
            'run_logs' => $queries['run_logs']->delete(),
        ];
    }
}
