<?php

namespace Tests\Feature;

use App\Application\Health\Checks\ScheduledTasksHealthCheck;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Health\Enums\Status;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Tests\TestCase;

class ScheduledTasksHealthCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_passes_when_monitored_tasks_ran_on_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:00:00'));
        Artisan::call('schedule-monitor:sync');
        MonitoredScheduledTask::query()->update(['last_started_at' => now(), 'last_finished_at' => now()]);

        $result = ScheduledTasksHealthCheck::new()->run();

        $this->assertEquals(Status::ok(), $result->status);
        $this->assertSame('0', $result->shortSummary);
    }

    public function test_it_fails_when_a_monitored_task_failed_on_its_last_run(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:00:00'));
        Artisan::call('schedule-monitor:sync');
        MonitoredScheduledTask::query()->update(['last_started_at' => now(), 'last_finished_at' => now()]);
        MonitoredScheduledTask::findByName('auth:clear-resets')?->update(['last_failed_at' => now()]);

        $result = ScheduledTasksHealthCheck::new()->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertStringContainsString('auth:clear-resets: failed', $result->notificationMessage);
    }

    public function test_it_fails_when_a_monitored_task_finished_too_late(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:00:00'));
        Artisan::call('schedule-monitor:sync');
        MonitoredScheduledTask::query()->update(['last_started_at' => now(), 'last_finished_at' => now()]);
        MonitoredScheduledTask::findByName('auth:clear-resets')?->update(['last_started_at' => now()->subHours(3), 'last_finished_at' => now()->subHours(3)]);

        $result = ScheduledTasksHealthCheck::new()->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertStringContainsString('auth:clear-resets: late', $result->notificationMessage);
    }
}
