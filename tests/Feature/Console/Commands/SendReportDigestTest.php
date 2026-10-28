<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\BuildOperationalDigestEmail;
use App\Models\RunLog;
use App\Models\Store;
use App\Notifications\ReportDigestNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendReportDigestTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array<string, mixed>> */
    private function digestRules(array $overrides = []): array
    {
        return ['run_audit' => ['mode' => 'digest', 'threshold' => 1, 'include_zero' => false, 'email' => ''], ...$overrides];
    }

    private function store(array $rules, ?string $defaultEmail = 'ops@example.com'): Store
    {
        return Store::factory()->create(['email_rules' => $rules, 'default_alert_email' => $defaultEmail]);
    }

    public function test_todays_matching_runs_are_sent_to_the_rule_recipient(): void
    {
        Notification::fake();
        $store = $this->store($this->digestRules());
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'issues_found', 'rows_found' => 4]);

        $this->artisan('reports:email-digest')->assertExitCode(0);

        Notification::assertSentOnDemand(ReportDigestNotification::class, function (ReportDigestNotification $notification, array $channels, object $notifiable): bool {
            return $notification->sections === [['tool' => 'run_audit', 'rows' => 4]] && $notifiable->routes['mail'] === 'ops@example.com';
        });
    }

    public function test_old_failed_and_scheduled_runs_are_left_out_of_the_digest(): void
    {
        Notification::fake();
        $store = $this->store($this->digestRules());
        $this->travelTo(now()->subDay());
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'issues_found', 'rows_found' => 9]);
        $this->travelBack();
        $this->travelTo(self::FROZEN_NOW);
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'error', 'rows_found' => 7]);
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'issues_found', 'rows_found' => 5, 'meta' => ['report_schedule_id' => 3]]);

        $this->artisan('reports:email-digest')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_runs_below_the_threshold_and_immediate_rules_are_not_digested(): void
    {
        Notification::fake();
        $store = $this->store($this->digestRules(['run_audit' => ['mode' => 'digest', 'threshold' => 10, 'include_zero' => false, 'email' => ''], 'email_check' => ['mode' => 'immediate', 'threshold' => 1, 'include_zero' => false, 'email' => '']]));
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'issues_found', 'rows_found' => 3]);
        RunLog::create(['store_id' => $store->id, 'tool' => 'email_check', 'status' => 'issues_found', 'rows_found' => 3]);

        $this->artisan('reports:email-digest')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_a_store_without_any_recipient_sends_nothing(): void
    {
        Notification::fake();
        $store = $this->store($this->digestRules(), null);
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'issues_found', 'rows_found' => 4]);

        $this->artisan('reports:email-digest')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_the_operational_digest_is_built_by_a_queued_job_instead_of_a_plain_digest_email(): void
    {
        Notification::fake();
        Queue::fake();
        $store = $this->store($this->digestRules(['operational_digest' => ['mode' => 'digest', 'threshold' => 0, 'include_zero' => true, 'email' => '']]));
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'issues_found', 'rows_found' => 2]);

        $this->artisan('reports:email-digest')->assertExitCode(0);

        Queue::assertPushed(BuildOperationalDigestEmail::class, fn (BuildOperationalDigestEmail $job): bool => $job->storeId === $store->id);
        Notification::assertNothingSent();
    }

    public function test_stores_without_email_rules_are_ignored(): void
    {
        Notification::fake();
        Queue::fake();
        Store::factory()->create(['email_rules' => null]);

        $this->artisan('reports:email-digest')->assertExitCode(0);

        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
