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
}
