<?php

namespace App\Console\Commands;

use App\Application\Reports\DispatchScheduledReport;
use App\Models\ReportSchedule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('reports:queue-scheduled-reports')]
#[Description('Queue due enabled report schedules without replaying missed occurrences.')]
class QueueScheduledReports extends Command
{
    public function handle(DispatchScheduledReport $dispatch): int
    {
        $queued = $failures = 0;
        foreach (ReportSchedule::query()->where('enabled', true)->where('next_run_at', '<=', now())->lazyById(100) as $schedule) {
            try {
                $queued += $dispatch->handle($schedule) === null ? 0 : 1;
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
            }
        }
        $this->info("Queued {$queued} scheduled report(s). {$failures} schedule(s) failed.");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
