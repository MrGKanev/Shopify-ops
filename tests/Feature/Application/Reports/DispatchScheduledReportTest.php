<?php

namespace Tests\Feature\Application\Reports;

use App\Application\Reports\DispatchScheduledReport;
use App\Jobs\RunQueuedReport;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DispatchScheduledReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_disabled_schedule_is_not_dispatched_automatically_but_a_manual_run_still_works(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $schedule = $this->schedule(['enabled' => false, 'next_run_at' => now()->subMinute()]);
        Queue::fake([RunQueuedReport::class]);

        $this->assertNull(app(DispatchScheduledReport::class)->handle($schedule));
        $this->assertSame(0, ReportRun::count());
        $this->assertSame('2026-10-25 11:59:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i:s'));

        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);

        $this->assertNotNull($run);
        $this->assertNull($run->scheduled_for);
        $this->assertSame('2026-10-25 11:59:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i:s'));
        Queue::assertPushed(RunQueuedReport::class, 1);
    }

    public function test_a_schedule_without_or_before_its_next_run_is_left_alone(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        Queue::fake([RunQueuedReport::class]);
        $notYet = $this->schedule(['enabled' => true, 'next_run_at' => now()->addSecond()]);
        $unset = $this->schedule(['enabled' => true, 'next_run_at' => null]);

        $this->assertNull(app(DispatchScheduledReport::class)->handle($notYet));
        $this->assertNull(app(DispatchScheduledReport::class)->handle($unset));

        $this->assertSame(0, ReportRun::count());
        Queue::assertNothingPushed();
        $this->assertSame('2026-10-25 12:00:01', $notYet->fresh()->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_a_due_schedule_is_dispatched_exactly_at_its_slot(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $schedule = $this->schedule(['enabled' => true, 'next_run_at' => now()]);
        Queue::fake([RunQueuedReport::class]);

        $run = app(DispatchScheduledReport::class)->handle($schedule);

        $this->assertSame('2026-10-25 12:00:00', $run->scheduled_for->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-26 07:00:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_an_unfinished_run_blocks_a_second_dispatch_without_consuming_the_slot(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $schedule = $this->schedule(['enabled' => true, 'next_run_at' => now()]);
        Queue::fake([RunQueuedReport::class]);
        app(DispatchScheduledReport::class)->handle($schedule->fresh()->forceFill(['next_run_at' => now()]));
        ReportSchedule::query()->whereKey($schedule->getKey())->update(['next_run_at' => now()]);

        $this->assertNull(app(DispatchScheduledReport::class)->handle($schedule));
        $this->assertSame('2026-10-25 12:00:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, ReportRun::count());
        $this->expectException(ValidationException::class);
        app(DispatchScheduledReport::class)->handle($schedule, manual: true);
    }

    public function test_a_schedule_cannot_outlive_its_store_so_dispatch_never_runs_without_one(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $schedule = $this->schedule(['enabled' => true, 'next_run_at' => now()]);
        Queue::fake([RunQueuedReport::class]);

        $schedule->store->delete();

        $this->assertSame(0, ReportSchedule::count());
        try {
            app(DispatchScheduledReport::class)->handle($schedule);
            $this->fail('A deleted schedule must not run.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, ReportRun::count());
        }
        Queue::assertNothingPushed();
    }

    public function test_a_failed_dispatch_keeps_the_occurrence_visible_as_a_failed_run_for_its_slot(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $schedule = $this->schedule(['enabled' => true, 'next_run_at' => now()->subMinutes(5)]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));

        try {
            app(DispatchScheduledReport::class)->handle($schedule);
            $this->fail('The queue failure must reach the caller.');
        } catch (RuntimeException) {
        }

        $run = $schedule->runs()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('2026-10-25 11:55:00', $run->scheduled_for->format('Y-m-d H:i:s'));
        $this->assertSame('failed', $schedule->fresh()->last_run_status);
        $this->assertSame(['failed'], ReportRun::query()->pluck('status')->all());
    }

    public function test_a_previous_failed_run_does_not_block_the_next_occurrence(): void
    {
        $this->travelTo('2026-10-26 07:00:00 UTC');
        $schedule = $this->schedule(['enabled' => true, 'next_run_at' => now()]);
        Queue::fake([RunQueuedReport::class]);
        $first = app(DispatchScheduledReport::class)->handle($schedule);
        $first->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        $this->travel(1)->day();

        $second = app(DispatchScheduledReport::class)->handle($schedule->fresh());

        $this->assertNotNull($second);
        $this->assertSame(2, ReportRun::count());
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(array $attributes = []): ReportSchedule
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);

        return ReportSchedule::factory()->for($store)->create(['user_id' => $admin->id, 'tool' => 'on_hold_stall', 'arguments' => ['2026-10-01', '2026-10-24'], 'parameters' => [], 'uses_date_range' => true, ...$attributes]);
    }
}
