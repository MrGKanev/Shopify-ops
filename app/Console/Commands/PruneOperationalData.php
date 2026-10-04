<?php

namespace App\Console\Commands;

use App\Application\Operations\PruneOperationalData as OperationalDataPruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:prune-data
    {--webhook-days=30 : Clear webhook payloads (customer data) older than this many days}
    {--login-days=90 : Delete login attempt records older than this many days (active bans are kept)}
    {--delivery-days=90 : Delete notification delivery records older than this many days}
    {--run-log-days=180 : Delete run history older than this many days}
    {--dry-run : Only count what would be removed}')]
#[Description('Remove old operational history and strip customer data from old webhook payloads. Run manually.')]
class PruneOperationalData extends Command
{
    public function handle(OperationalDataPruner $pruner): int
    {
        $retentionDays = [
            'webhook_payload_days' => (int) $this->option('webhook-days'),
            'login_attempt_days' => (int) $this->option('login-days'),
            'notification_delivery_days' => (int) $this->option('delivery-days'),
            'run_log_days' => (int) $this->option('run-log-days'),
        ];

        if (min($retentionDays) < 1) {
            $this->error('Every retention period must be at least 1 day.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = $pruner->handle($retentionDays, $dryRun);

        $this->table(
            ['Data', $dryRun ? 'Would remove' : 'Removed', 'Older than'],
            [
                ['Webhook payloads (cleared, rows kept)', $counts['webhook_payloads'], $retentionDays['webhook_payload_days'].' days'],
                ['Login attempts', $counts['login_attempts'], $retentionDays['login_attempt_days'].' days'],
                ['Notification deliveries', $counts['notification_deliveries'], $retentionDays['notification_delivery_days'].' days'],
                ['Run history', $counts['run_logs'], $retentionDays['run_log_days'].' days'],
            ],
        );

        return self::SUCCESS;
    }
}
