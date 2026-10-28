<?php

namespace Tests\Feature;

use App\Application\Health\CheckOperationalAlerts;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Listeners\AlertOnOperationalFailure;
use App\Models\OperationalIssue;
use App\Models\RunLog;
use App\Models\Store;
use App\Notifications\OperationalAlertNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Horizon\WaitTimeCalculator;
use Mockery;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class ImportFreshnessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_page_is_gated_store_scoped_and_ages_only_active_run_audit_issues(): void
    {
        $this->travelTo('2026-10-28 12:00:00 UTC');
        $this->get(route('operational-issues.import-freshness'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('operational-issues.import-freshness'))->assertForbidden();

        [$operator, $store] = $this->userWithStore(true);
        $store->update(['import_grace_hours' => 6]);
        OperationalIssue::factory()->for($store)->create(['reference' => '#1001', 'title' => 'Missing order #1001', 'first_seen_at' => now()->subHours(30), 'occurrences' => 4]);
        OperationalIssue::factory()->for($store)->create(['reference' => '#1002', 'title' => 'Missing order #1002', 'first_seen_at' => now()->subHours(2)]);
        OperationalIssue::factory()->for($store)->create(['reference' => '#1003', 'status' => 'resolved', 'first_seen_at' => now()->subHours(90)]);
        OperationalIssue::factory()->for($store)->create(['reference' => '#1004', 'source_tool' => 'delivery_watch', 'first_seen_at' => now()->subHours(90)]);
        OperationalIssue::factory()->create(['reference' => '#9999', 'first_seen_at' => now()->subHours(90)]);
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'issues_found']);

        $response = $this->actingAs($operator)->get(route('operational-issues.import-freshness'))->assertOk()->assertSeeText('#1001')->assertSeeText('#1002')->assertDontSeeText('#1003')->assertDontSeeText('#1004')->assertDontSeeText('#9999');

        $freshness = $response->viewData('freshness');
        $this->assertSame(['#1001', '#1002'], array_column($freshness['rows'], 'reference'));
        $this->assertSame([30, 2], array_column($freshness['rows'], 'age_hours'));
        $this->assertSame(['beyond_grace', 'within_grace'], array_column($freshness['rows'], 'state'));
        $this->assertNull($response->viewData('refresh'));
        $this->assertSame('issues_found', $response->viewData('last_run')['status']);
    }

    public function test_quiet_store_shows_an_empty_state_not_a_stale_warning(): void
    {
        [$operator] = $this->userWithStore(true);

        $response = $this->actingAs($operator)->get(route('operational-issues.import-freshness'))->assertOk()->assertSeeText(__('No expected orders are currently absent from ShipStation'));

        $this->assertFalse($response->viewData('freshness')['stale']);
        $this->assertNull($response->viewData('last_run'));
    }

    public function test_a_failed_latest_audit_is_called_out(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'ok']);
        RunLog::create(['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'error']);

        $response = $this->actingAs($operator)->get(route('operational-issues.import-freshness'))->assertOk()->assertSeeText(__('The latest audit failed, so the ages below come from earlier runs.'));

        $this->assertSame('error', $response->viewData('last_run')['status']);
        $this->assertTrue(now()->equalTo($response->viewData('last_success_at')));
    }

    public function test_grace_window_is_validated_and_saved_for_the_active_store_only(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $other = Store::factory()->create();
        $this->actingAs($operator);

        $this->put(route('operational-issues.import-freshness.update'), ['import_grace_hours' => 0])->assertSessionHasErrors('import_grace_hours');
        $this->put(route('operational-issues.import-freshness.update'), ['import_grace_hours' => 721])->assertSessionHasErrors('import_grace_hours');
        $this->put(route('operational-issues.import-freshness.update'), ['import_grace_hours' => 12])->assertRedirect(route('operational-issues.import-freshness'));

        $this->assertSame(12, $store->fresh()->importGraceHours());
        $this->assertSame(Store::DEFAULT_IMPORT_GRACE_HOURS, $other->fresh()->importGraceHours());
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->put(route('operational-issues.import-freshness.update'), ['import_grace_hours' => 3])->assertForbidden();
    }

    public function test_refresh_status_is_read_on_request_and_shown_as_reported(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('refreshStatus')->once()->with(42)->andReturn(['status' => 'Complete', 'refresh_date' => '2026-10-28T10:00:00']);

        $response = $this->actingAs($operator)->get(route('operational-issues.import-freshness', ['status' => 1]))->assertOk()->assertSeeText('Complete')->assertSeeText(__('This is shown as reported. A refresh date is not proof that the import completed, and it is not used to judge the connection.'));

        $this->assertSame('reported', $response->viewData('refresh')['state']);
    }

    public function test_unavailable_refresh_status_is_reported_as_unavailable_not_as_a_failed_connection(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('refreshStatus')->andThrow(new UnexpectedResponse('secret boom'));

        $this->actingAs($operator)->get(route('operational-issues.import-freshness', ['status' => 1]))->assertOk()->assertSeeText(__('ShipStation did not return a refresh status for this store or account.'))->assertDontSeeText('secret boom');
    }

    public function test_alert_fires_once_per_store_only_for_orders_beyond_their_grace_window(): void
    {
        $this->travelTo('2026-10-28 12:00:00 UTC');
        config(['queue.default' => 'sync', 'services.slack.notifications.webhook_url' => 'https://hooks.slack.com/services/test']);
        Notification::fake();
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->timestamp);
        $late = Store::factory()->create(['label' => 'Late store', 'import_grace_hours' => 6]);
        $fresh = Store::factory()->create(['label' => 'Fresh store', 'import_grace_hours' => 48]);
        Store::factory()->create(['label' => 'Quiet store', 'import_grace_hours' => 1]);
        OperationalIssue::factory()->for($late)->create(['first_seen_at' => now()->subHours(7)]);
        OperationalIssue::factory()->for($late)->create(['first_seen_at' => now()->subHours(9)]);
        OperationalIssue::factory()->for($fresh)->create(['first_seen_at' => now()->subHours(7)]);
        OperationalIssue::factory()->for($late)->create(['status' => 'resolved', 'first_seen_at' => now()->subHours(99)]);
        $check = new CheckOperationalAlerts(new AlertOnOperationalFailure, Mockery::mock(WaitTimeCalculator::class));

        $check->handle();
        $check->handle();

        Notification::assertSentOnDemandTimes(OperationalAlertNotification::class, 1);
        Notification::assertSentOnDemand(OperationalAlertNotification::class, fn (OperationalAlertNotification $alert): bool => $alert->category === 'import_lag:'.$late->id && str_contains($alert->summary, '2 expected orders') && str_contains($alert->summary, 'Late store'));
    }
}
