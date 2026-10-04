<?php

namespace App\Console\Commands;

use App\Application\Notifications\ReportNotifier;
use App\Models\Store;
use App\Notifications\ReportDigestNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('reports:email-digest')]
#[Description('Queue daily report email digests')]
class SendReportDigest extends Command
{
    public function handle(ReportNotifier $notifier): int
    {
        Store::whereNotNull('email_rules')->each(function (Store $store) use ($notifier): void {
            $sectionsByRecipient = [];
            foreach ($store->email_rules->rules as $tool => $rule) {
                $recipient = $notifier->emailRecipient($store, $rule);
                if ($rule->mode !== 'digest' || $recipient === '') {
                    continue;
                }

                $run = $store->runLogs()->where('tool', $tool)->where('status', '!=', 'error')->where('created_at', '>=', today())->latest()->first();
                $rows = (int) ($run?->rows_found ?? 0);
                if ($run && $notifier->emailRuleMatches($rule, $rows)) {
                    $sectionsByRecipient[$recipient][] = ['tool' => $tool, 'rows' => $rows];
                }
            }

            foreach ($sectionsByRecipient as $email => $sections) {
                Notification::route('mail', $email)->notify(new ReportDigestNotification($store->label, $sections));
            }
        });

        return self::SUCCESS;
    }
}
