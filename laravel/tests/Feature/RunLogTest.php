<?php

namespace Tests\Feature;

use App\Application\Reports\RecordRun;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ScanDiscordNotification;
use App\Notifications\ScanSlackNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RunLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recorder_defaults_caps_history_and_page_is_store_scoped(): void
    {
        $store = Store::factory()->create();
        $foreign = Store::factory()->create();
        $user = User::factory()->create();
        $user->stores()->attach($store);
        $recorder = app(RecordRun::class);
        for ($index = 0; $index < 501; $index++) {
            $recorder->handle($store, ['tool' => "scan-{$index}"]);
        }
        $recorder->handle($foreign, ['tool' => 'secret']);

        $this->assertSame(500, $store->runLogs()->count());
        $this->assertDatabaseMissing('run_logs', ['store_id' => $store->id, 'tool' => 'scan-0']);
        $latest = $store->runLogs()->latest('id')->firstOrFail();
        $this->assertSame(['ok', '', null], [$latest->status, $latest->error, $latest->duration_seconds]);
        $this->actingAs($user)->get('/run-logs')->assertOk()->assertSeeText('scan-500')->assertDontSeeText('secret')->assertDontSee('<script>', false);
    }

    public function test_page_requires_authentication(): void
    {
        $this->get('/run-logs')->assertRedirect(route('login'));
    }

    public function test_scan_rules_dispatch_only_at_threshold(): void
    {
        Notification::fake();
        config()->set('services.slack.notifications.webhook_url', 'https://hooks.slack.com/services/T/B/test');
        config()->set('services.discord.notifications.webhook_url', 'https://discord.com/api/webhooks/1/test');
        $store = Store::factory()->create(['slack_rules' => ['audit_enabled' => true, 'audit_min_missing' => 0, 'include_zero_audit' => true, 'scan_enabled' => true, 'scan_min_rows' => 2, 'mentions' => 'U012ABC3DE'], 'discord_rules' => ['audit_enabled' => true, 'audit_min_missing' => 0, 'include_zero_audit' => true, 'scan_enabled' => true, 'scan_min_rows' => 2]]);
        $recorder = app(RecordRun::class);
        $recorder->handle($store, ['tool' => 'scan_test', 'rows_found' => 1]);
        $recorder->handle($store, ['tool' => 'scan_test', 'rows_found' => 2]);
        $recorder->handle($store, ['tool' => 'scan_test', 'rows_found' => 3, 'status' => 'error']);

        Notification::assertSentOnDemandTimes(ScanSlackNotification::class, 1);
        Notification::assertSentOnDemandTimes(ScanDiscordNotification::class, 1);
    }
}
