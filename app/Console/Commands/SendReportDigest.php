<?php

namespace App\Console\Commands;

use App\Application\Notifications\ReportNotifier;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunOperationalDigest;
use App\Jobs\BuildOperationalDigestEmail;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:email-digest')]
#[Description('Queue daily report email digests')]
class SendReportDigest extends Command
{
    public function handle(ReportNotifier $notifier, QueuedReportRunner $reports): int
    {
        Store::whereNotNull('email_rules')->each(function (Store $store) use ($notifier, $reports): void {
            $sections = [];
            foreach ($store->email_rules->rules as $tool => $rule) {
                if ($tool === 'operational_digest') {
                    continue;
                }
                $run = $store->runLogs()->where('tool', $tool)->where('status', '!=', 'error')->where('created_at', '>=', today())->latest('id')->first();
                if ($run !== null) {
                    $sections[] = ['tool' => $tool, 'rows' => (int) $run->rows_found];
                }
            }
            if ($notifier->hasDigestRule($store, 'operational_digest')) {
                $date = CarbonImmutable::now($store->shopTimezone());
                $policy = $store->operationalDigestPolicy();
                $start = $date->subDays($policy['lookback_days'] - 1)->toDateString();
                $end = $date->toDateString();
                $run = $reports->createRun($store, 'operational_digest', RunOperationalDigest::class, [$start, $end, $policy['sla_days']], $start, $end);
                BuildOperationalDigestEmail::dispatch($store->id, $run->id, $sections);
            } else {
                $notifier->emailDigest($store, $sections);
            }

        });

        return self::SUCCESS;
    }
}
