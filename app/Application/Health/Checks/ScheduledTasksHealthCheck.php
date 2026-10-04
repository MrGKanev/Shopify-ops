<?php

namespace App\Application\Health\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\ScheduleMonitor\Support\ScheduledTasks\ScheduledTasks;
use Spatie\ScheduleMonitor\Support\ScheduledTasks\Tasks\Task;

/**
 * Fails when a monitored scheduled task failed on its last run or did not finish within its grace time.
 *
 * Tasks become monitored after `php artisan schedule-monitor:sync`.
 */
class ScheduledTasksHealthCheck extends Check
{
    public function run(): Result
    {
        $problems = ScheduledTasks::createForSchedule()
            ->monitoredTasks()
            ->map(fn (Task $task): ?string => match (true) {
                $task->lastRunFailed() => "{$task->name()}: failed",
                $task->lastRunFinishedTooLate() => "{$task->name()}: late",
                default => null,
            })
            ->filter()
            ->values();

        $result = Result::make()->shortSummary((string) $problems->count());

        return $problems->isEmpty()
            ? $result->ok()
            : $result->failed($problems->implode(' | '));
    }
}
