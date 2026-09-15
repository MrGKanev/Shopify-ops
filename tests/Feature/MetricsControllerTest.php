<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\RunLog;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MetricsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_not_found_when_no_scrape_token_is_configured(): void
    {
        config(['services.metrics.token' => '']);

        $this->get('/metrics')->assertNotFound();
    }

    public function test_rejects_requests_with_a_missing_or_wrong_token(): void
    {
        config(['services.metrics.token' => 'secret-scrape-token']);

        $this->get('/metrics')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer wrong-token')->get('/metrics')->assertUnauthorized();
    }

    public function test_returns_prometheus_counters_without_pii_or_secrets(): void
    {
        config(['services.metrics.token' => 'secret-scrape-token']);
        $store = Store::factory()->create(['label' => 'Acme Store']);
        RunLog::create(['store_id' => $store->id, 'tool' => 'same_ip', 'status' => 'ok']);
        RunLog::create(['store_id' => $store->id, 'tool' => 'same_ip', 'status' => 'ok']);
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'error']);
        NotificationDelivery::create(['channel' => 'mail', 'notification_type' => 'ReportEmailNotification', 'recipient' => 'ops@example.com', 'status' => 'sent']);
        NotificationDelivery::create(['channel' => 'slack', 'notification_type' => 'AuditSlackNotification', 'store_label' => 'Acme Store', 'status' => 'failed', 'error_category' => 'RuntimeException']);
        DB::table('failed_jobs')->insert(['uuid' => 'test-uuid', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'secret webhook token boom', 'failed_at' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer secret-scrape-token')->get('/metrics');

        $response->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $body = $response->getContent();
        $this->assertStringContainsString('checker_runs_total{tool="same_ip",status="ok"} 2', $body);
        $this->assertStringContainsString('checker_runs_total{tool="run_audit",status="error"} 1', $body);
        $this->assertStringContainsString('checker_notification_deliveries_total{channel="mail",status="sent"} 1', $body);
        $this->assertStringContainsString('checker_notification_deliveries_total{channel="slack",status="failed"} 1', $body);
        $this->assertStringContainsString('checker_failed_jobs_total 1', $body);
        $this->assertStringContainsString('checker_queue_pending_jobs', $body);
        $this->assertStringNotContainsString('Acme Store', $body);
        $this->assertStringNotContainsString('ops@example.com', $body);
        $this->assertStringNotContainsString('secret webhook token boom', $body);
        $this->assertStringNotContainsString('secret-scrape-token', $body);
    }
}
