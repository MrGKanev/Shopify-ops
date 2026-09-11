<?php

namespace Tests\Feature;

use App\Application\Reports\RecordRun;
use App\Models\Store;
use App\Notifications\ReportDigestNotification;
use App\Notifications\ReportEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailRulesDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_immediate_delivery_respects_threshold_and_zero_setting(): void
    {
        Notification::fake();
        $store = Store::factory()->create(['email_rules' => ['scan_test' => ['mode' => 'immediate', 'threshold' => 2, 'include_zero' => false, 'email' => 'ops@example.com']]]);
        app(RecordRun::class)->handle($store, ['tool' => 'scan_test', 'rows_found' => 1]);
        app(RecordRun::class)->handle($store, ['tool' => 'scan_test', 'rows_found' => 2]);
        app(RecordRun::class)->handle($store, ['tool' => 'scan_test', 'rows_found' => 3, 'status' => 'error']);

        Notification::assertSentOnDemandTimes(ReportEmailNotification::class, 1);
    }

    public function test_digest_queues_latest_qualifying_runs_grouped_by_recipient(): void
    {
        Notification::fake();
        $store = Store::factory()->create(['email_rules' => ['run_audit' => ['mode' => 'digest', 'threshold' => 0, 'include_zero' => true, 'email' => 'ops@example.com'], 'scan_test' => ['mode' => 'digest', 'threshold' => 2, 'include_zero' => false, 'email' => 'ops@example.com']]]);
        $store->runLogs()->create(['tool' => 'run_audit', 'rows_found' => 0]);
        $store->runLogs()->create(['tool' => 'scan_test', 'rows_found' => 2]);

        Artisan::call('reports:email-digest');

        Notification::assertSentOnDemand(ReportDigestNotification::class, fn (ReportDigestNotification $notification): bool => count($notification->sections) === 2);
    }

    public function test_digest_excludes_a_stale_run_from_yesterday_even_within_the_last_24_hours(): void
    {
        Notification::fake();
        $store = Store::factory()->create(['email_rules' => ['scan_test' => ['mode' => 'digest', 'threshold' => 0, 'include_zero' => true, 'email' => 'ops@example.com']]]);
        $todayStart = today();
        $this->travelTo($todayStart->copy()->subMinutes(30));
        $store->runLogs()->create(['tool' => 'scan_test', 'rows_found' => 5]);
        $this->travelTo($todayStart->copy()->addHours(8));

        Artisan::call('reports:email-digest');

        Notification::assertNothingSent();
    }

    public function test_non_audit_tools_clamp_a_zero_threshold_to_one_but_run_audit_may_use_zero(): void
    {
        Notification::fake();
        $store = Store::factory()->create(['email_rules' => [
            'scan_test' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => true, 'email' => 'ops@example.com'],
            'run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => true, 'email' => 'ops@example.com'],
        ]]);

        app(RecordRun::class)->handle($store, ['tool' => 'scan_test', 'rows_found' => 0]);
        app(RecordRun::class)->handle($store, ['tool' => 'run_audit', 'rows_found' => 0]);

        Notification::assertSentOnDemandTimes(ReportEmailNotification::class, 1);
        Notification::assertSentOnDemand(ReportEmailNotification::class, fn (ReportEmailNotification $notification): bool => $notification->tool === 'run_audit');
    }
}
