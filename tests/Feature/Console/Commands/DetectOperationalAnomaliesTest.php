<?php

namespace Tests\Feature\Console\Commands;

use App\Models\OperationalIssue;
use App\Models\Store;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DetectOperationalAnomaliesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_an_issue_for_a_webhook_failure_spike(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $store = Store::factory()->create();
        WebhookEvent::factory()->count(3)->for($store)->create([
            'status' => 'failed',
            'occurred_at' => now()->subMinutes(20),
        ]);

        $this->artisan('operations:detect-anomalies')
            ->expectsOutput('Detected 1 operational anomaly signal(s).')
            ->assertSuccessful();

        $issue = $store->operationalIssues()->where('reference', 'webhook_failures')->sole();
        $this->assertSame('high', $issue->priority);
        $this->assertSame(3, $issue->payload['current']);
        $this->assertSame(0, $issue->payload['baseline_average']);
    }

    public function test_it_uses_the_historical_baseline_and_keeps_stores_isolated(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();
        WebhookEvent::factory()->count(3)->for($store)->create(['status' => 'failed', 'occurred_at' => now()->subMinutes(10)]);
        WebhookEvent::factory()->count(48)->for($store)->create(['status' => 'failed', 'occurred_at' => now()->subHours(2)]);
        WebhookEvent::factory()->count(3)->for($otherStore)->create(['status' => 'failed', 'occurred_at' => now()->subMinutes(10)]);

        $this->artisan('operations:detect-anomalies')->assertSuccessful();

        $this->assertFalse($store->operationalIssues()->where('source_tool', 'anomaly_detection')->exists());
        $this->assertTrue($otherStore->operationalIssues()->where('reference', 'webhook_failures')->exists());
    }

    public function test_it_detects_audit_and_issue_spikes_and_resolves_recovered_signals(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $store = Store::factory()->create();
        foreach (range(1, 2) as $index) {
            $store->runLogs()->create(['tool' => "audit_{$index}", 'status' => 'error', 'error' => '', 'created_at' => now()->subHours($index)]);
        }
        OperationalIssue::factory()->count(5)->for($store)->create(['created_at' => now()->subHours(3)]);

        $this->artisan('operations:detect-anomalies')->assertSuccessful();

        $this->assertSame(2, $store->operationalIssues()->where('source_tool', 'anomaly_detection')->where('status', 'open')->count());

        $this->travel(2)->days();
        $this->artisan('operations:detect-anomalies')->assertSuccessful();

        $this->assertSame(2, $store->operationalIssues()->where('source_tool', 'anomaly_detection')->where('status', 'resolved')->count());
    }

    public function test_the_anomaly_scan_is_scheduled_hourly_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'operations:detect-anomalies'));

        $this->assertNotNull($event);
        $this->assertSame('10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_it_flags_a_missed_scheduled_audit_and_resolves_it_after_success(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 11:05:00'));
        $store = Store::factory()->create(['scheduled_audit_enabled' => true, 'scheduled_audit_time' => '09:00']);
        $this->artisan('operations:detect-anomalies')->assertSuccessful();

        $issue = $store->operationalIssues()->where('reference', 'scheduled_audit_stale')->sole();
        $this->assertSame('open', $issue->status);
        $this->assertSame('Scheduled audit has not completed', $issue->title);

        $this->travelTo(Carbon::parse('2026-09-16 01:05:00'));
        $this->artisan('operations:detect-anomalies')->assertSuccessful();
        $this->assertSame('open', $issue->fresh()->status);

        $this->travelTo(Carbon::parse('2026-09-16 10:05:00'));
        $store->auditJobs()->create(['status' => 'completed', 'start_date' => today()->subDays(30), 'end_date' => today(), 'finished_at' => now()]);
        $this->artisan('operations:detect-anomalies')->assertSuccessful();

        $this->assertSame('resolved', $issue->fresh()->status);
    }

    public function test_it_does_not_flag_an_early_morning_audit_before_its_scheduled_time(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 00:05:00'));
        $store = Store::factory()->create(['scheduled_audit_enabled' => true, 'scheduled_audit_time' => '00:30']);

        $this->artisan('operations:detect-anomalies')->assertSuccessful();
        $this->assertFalse($store->operationalIssues()->where('reference', 'scheduled_audit_stale')->exists());

        $this->travelTo(Carbon::parse('2026-09-15 01:35:00'));
        $this->artisan('operations:detect-anomalies')->assertSuccessful();
        $this->assertTrue($store->operationalIssues()->where('reference', 'scheduled_audit_stale')->exists());
    }
}
