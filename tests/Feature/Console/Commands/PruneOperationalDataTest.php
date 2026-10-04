<?php

namespace Tests\Feature\Console\Commands;

use App\Models\LoginAttempt;
use App\Models\NotificationDelivery;
use App\Models\ReportRun;
use App\Models\RunLog;
use App\Models\Store;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class PruneOperationalDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-04 12:00:00'));
    }

    public function test_it_clears_old_webhook_payloads_and_deletes_old_history(): void
    {
        $store = Store::factory()->create();
        $oldEvent = WebhookEvent::factory()->for($store)->create(['occurred_at' => now()->subDays(31), 'payload' => ['email' => 'buyer@example.com']]);
        $recentEvent = WebhookEvent::factory()->for($store)->create(['occurred_at' => now()->subDays(5), 'payload' => ['email' => 'recent@example.com']]);
        $oldAttempt = $this->loginAttempt('10.0.0.1', now()->subDays(91));
        $bannedAttempt = $this->loginAttempt('10.0.0.2', now()->subDays(91), bannedUntil: now()->addDay());
        $recentAttempt = $this->loginAttempt('10.0.0.3', now()->subDay());
        $oldDelivery = $this->delivery(now()->subDays(91));
        $recentDelivery = $this->delivery(now()->subDays(10));
        $oldRun = $this->runLog($store, now()->subDays(181));
        $oldReportRun = $this->reportRun($store, now()->subDays(8));
        $recentReportRun = $this->reportRun($store, now()->subDays(2));
        $recentRun = $this->runLog($store, now()->subDays(20));

        $this->artisan('ops:prune-data')->assertSuccessful();

        $this->assertSame([], $oldEvent->fresh()->payload);
        $this->assertNotNull($oldEvent->fresh()->payload_pruned_at);
        $this->assertSame(['email' => 'recent@example.com'], $recentEvent->fresh()->payload);
        $this->assertNull($oldAttempt->fresh());
        $this->assertNotNull($bannedAttempt->fresh());
        $this->assertNotNull($recentAttempt->fresh());
        $this->assertNull($oldDelivery->fresh());
        $this->assertNotNull($recentDelivery->fresh());
        $this->assertNull($oldRun->fresh());
        $this->assertNotNull($recentRun->fresh());
        $this->assertNull($oldReportRun->fresh());
        $this->assertNotNull($recentReportRun->fresh());
    }

    public function test_dry_run_only_counts_and_custom_periods_apply(): void
    {
        $store = Store::factory()->create();
        $event = WebhookEvent::factory()->for($store)->create(['occurred_at' => now()->subDays(10), 'payload' => ['email' => 'buyer@example.com']]);

        $this->artisan('ops:prune-data', ['--webhook-days' => 7, '--dry-run' => true])
            ->expectsOutputToContain('Would remove')
            ->assertSuccessful();
        $this->assertSame(['email' => 'buyer@example.com'], $event->fresh()->payload);

        $this->artisan('ops:prune-data', ['--webhook-days' => 7])->assertSuccessful();
        $this->assertSame([], $event->fresh()->payload);
    }

    public function test_it_rejects_a_retention_period_below_one_day_and_is_not_scheduled(): void
    {
        $this->artisan('ops:prune-data', ['--run-log-days' => 0])->assertExitCode(2);

        $this->assertFalse(collect(Schedule::events())->contains(fn ($event): bool => str_contains((string) $event->command, 'ops:prune-data')));
    }

    private function loginAttempt(string $ip, Carbon $updatedAt, ?Carbon $bannedUntil = null): LoginAttempt
    {
        $attempt = LoginAttempt::create(['ip' => $ip, 'attempts' => 3, 'first_attempt_at' => $updatedAt, 'banned_until' => $bannedUntil]);
        $attempt->forceFill(['updated_at' => $updatedAt])->saveQuietly();

        return $attempt;
    }

    private function delivery(Carbon $createdAt): NotificationDelivery
    {
        $delivery = NotificationDelivery::create(['channel' => 'mail', 'notification_type' => 'ReportEmailNotification', 'status' => 'sent']);
        $delivery->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $delivery;
    }

    private function reportRun(Store $store, Carbon $createdAt): ReportRun
    {
        $run = $store->reportRuns()->create(['tool' => 'address_check', 'report' => 'App\\Application\\Reports\\RunAddressCheckReport', 'arguments' => [], 'arguments_hash' => hash('sha256', 'x'), 'status' => 'completed']);
        $run->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $run;
    }

    private function runLog(Store $store, Carbon $createdAt): RunLog
    {
        $run = $store->runLogs()->create(['tool' => 'address_check', 'status' => 'ok']);
        $run->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $run;
    }
}
