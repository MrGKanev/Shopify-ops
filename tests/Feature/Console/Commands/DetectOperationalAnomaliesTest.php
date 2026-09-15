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
}
