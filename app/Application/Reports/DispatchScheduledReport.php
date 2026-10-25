<?php

namespace App\Application\Reports;

use App\Jobs\RunQueuedReport;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DispatchScheduledReport
{
    public function __construct(private readonly ScheduledReportPlan $plan, private readonly QueuedReportRunner $reports) {}

    public function handle(ReportSchedule $schedule, bool $manual = false): ?ReportRun
    {
        $run = DB::transaction(function () use ($schedule, $manual): ?ReportRun {
            $locked = ReportSchedule::query()->with('store')->lockForUpdate()->whereKey($schedule->getKey())->firstOrFail();
            $now = CarbonImmutable::now('UTC');
            if (! $manual && (! $locked->enabled || $locked->next_run_at === null || $locked->next_run_at->gt($now))) {
                return null;
            }
            if ($locked->runs()->whereIn('status', ['queued', 'running'])->exists()) {
                if ($manual) {
                    throw ValidationException::withMessages(['schedule' => __('This schedule already has a queued or running report.')]);
                }

                return null;
            }
            $input = $this->plan->inputs($locked, $now);
            $store = $locked->store ?? throw new \LogicException('The scheduled store no longer exists.');
            $action = $this->plan->action($locked, $store, requireCredentials: false);
            $run = $this->reports->createRun($store, $locked->tool, $action, $input['arguments'], $input['start'], $input['end'], $locked->user_id);
            $run->forceFill(['report_schedule_id' => $locked->getKey(), 'scheduled_for' => $manual ? null : $locked->next_run_at, 'parameters' => $input['parameters']])->save();
            if (! $manual) {
                $locked->update(['next_run_at' => $this->plan->nextRun($locked, $now->setTimezone($locked->timezone)->endOfDay())]);
            }

            return $run;
        });
        if ($run !== null) {
            try {
                RunQueuedReport::dispatch($run->getKey())->afterCommit();
            } catch (Throwable $exception) {
                $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
                $schedule->recordOutcome($run);
                throw $exception;
            }
        }

        return $run;
    }
}
