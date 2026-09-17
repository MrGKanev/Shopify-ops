<?php

namespace App\Console\Commands;

use App\Jobs\RunAuditJob;
use App\Models\AuditJob;
use App\Models\Store;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:queue-scheduled-audits')]
#[Description('Queue due daily core audits for stores that enabled automation.')]
class QueueScheduledAudits extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $time = now()->format('H:i:00');
        $queued = 0;

        Store::query()
            ->where('scheduled_audit_enabled', true)
            ->whereTime('scheduled_audit_time', $time)
            ->each(function (Store $store) use (&$queued): void {
                if ($store->missingShopifyCredentials() || $store->missingShipStationCredentials()) {
                    return;
                }

                $start = today()->subDays(30)->toDateString();
                $end = today()->toDateString();
                $alreadyQueued = AuditJob::query()
                    ->where('store_id', $store->getKey())
                    ->whereDate('start_date', $start)
                    ->whereDate('end_date', $end)
                    ->whereIn('status', ['queued', 'running', 'completed'])
                    ->exists();
                if ($alreadyQueued) {
                    return;
                }

                $auditJob = AuditJob::create(['store_id' => $store->getKey(), 'start_date' => $start, 'end_date' => $end]);
                RunAuditJob::dispatch($store->getKey(), $start, $end, $auditJob->getKey());
                $queued++;
            });

        $this->info("Queued {$queued} scheduled audit(s).");

        return self::SUCCESS;
    }
}
