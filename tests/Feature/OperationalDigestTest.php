<?php

namespace Tests\Feature;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunOperationalDigest;
use App\Domain\Reports\OperationalDigestAnalyzer;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Jobs\BuildOperationalDigestEmail;
use App\Models\OperationalIssue;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ReportDigestNotification;
use App\Support\UiFormat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OperationalDigestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_snapshot_combines_pending_orders_sync_findings_all_active_issues_and_upcoming_deadlines(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->fakeSources($store);
        OperationalIssue::factory()->for($store)->create(['title' => 'Warehouse review']);
        OperationalIssue::factory()->for($store)->create(['status' => 'resolved']);
        OperationalIssue::factory()->for(Store::factory())->create(['title' => 'Another store']);

        $result = app(RunOperationalDigest::class)->handle($store, '2026-10-01', '2026-10-07', 3);

        $this->assertSame(['paid_pending' => 2, 'sync_findings' => 2, 'sla_overdue' => 1, 'sla_due_soon' => 1, 'open_issues' => 1], $result->meta['counts']);
        $this->assertFalse($result->truncated);
        $this->assertCount(7, $result->rows);
        $this->assertStringContainsString('unavailable', $result->meta['coverage']['manifests']);
        $this->assertStringContainsString('does not confirm', $result->meta['coverage']['billing_refunds']);
        $this->assertDatabaseCount('operational_issues', 3);
    }

    public function test_unavailable_shopify_does_not_become_a_zero_or_leak_credentials(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        [$operator, $store] = $this->userWithStore(true, ['shipstation_api_key' => null, 'shipstation_api_secret' => null]);
        $this->mock(ShopifyTransport::class)->shouldReceive('paginateGraphql')->andThrow(new RuntimeException('secret-token'));

        $this->actingAs($operator)->post(route('reports.operational-digest.store'), $this->input())->assertSeeText('Unavailable')->assertSeeText('Shopify snapshot unavailable')->assertDontSeeText('secret-token');

        $result = $store->reportRuns()->sole()->result();
        $this->assertNull($result->meta['counts']['paid_pending']);
        $this->assertNull($result->meta['counts']['sync_findings']);
        $this->assertTrue($result->truncated);
    }

    public function test_shipstation_reconciliation_is_scoped_and_records_without_store_identity_make_it_partial(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $store = Store::factory()->create(['store_number' => '12']);
        $this->fakeSources($store, foreignOnly: true);

        $result = app(RunOperationalDigest::class)->handle($store, '2026-10-01', '2026-10-07', 3);

        $this->assertSame(0, $result->meta['counts']['sync_findings']);
        $this->assertSame('partial', $result->meta['coverage']['shipstation']);
        $this->assertTrue($result->truncated);
    }

    public function test_unconfirmed_access_to_older_orders_marks_the_snapshot_partial(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $store = Store::factory()->create(['store_number' => '12']);
        $this->fakeSources($store);
        app(ShopifyTransport::class)->shouldReceive('graphql')->once()->andReturn(['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_orders']]]]]);

        $result = app(RunOperationalDigest::class)->handle($store, '2026-07-01', '2026-10-07', 3);

        $this->assertSame('partial', $result->meta['coverage']['shopify']);
        $this->assertStringContainsString('read_all_orders', $result->meta['coverage']['older_orders']);
        $this->assertTrue($result->truncated);
    }

    public function test_sla_boundaries_use_shop_timezone_and_exact_24_hour_horizon(): void
    {
        $orders = [['id' => 1, 'name' => '#1001', 'created_at' => '2026-10-04T16:00:00Z', 'financial_status' => 'paid', 'fulfillment_status' => null]];
        $analyzer = new OperationalDigestAnalyzer;
        $now = CarbonImmutable::parse('2026-10-06T16:00:00Z')->setTimezone('America/New_York');
        $result = $analyzer->analyze($orders, [], [], 3, $now);
        $this->assertSame(1, $result['counts']['sla_due_soon']);
        $this->assertSame(0, $result['counts']['sla_overdue']);
        $atDeadline = $analyzer->analyze($orders, [], [], 3, $now->addDay());
        $this->assertSame(1, $atDeadline['counts']['sla_overdue']);
        $this->assertSame(0, $atDeadline['counts']['sla_due_soon']);
    }

    public function test_paid_cancelled_and_fulfilled_active_status_conflicts_are_visible(): void
    {
        $result = (new OperationalDigestAnalyzer)->analyze([
            ['id' => 1, 'name' => '#1001', 'created_at' => '2026-10-06', 'financial_status' => 'partially_refunded', 'fulfillment_status' => null],
            ['id' => 2, 'name' => '#1002', 'created_at' => '2026-10-06', 'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled'],
        ], [['orderNumber' => '1001', 'orderStatus' => 'cancelled'], ['orderNumber' => '1002', 'orderStatus' => 'awaiting_shipment']], [], 3, CarbonImmutable::parse('2026-10-07'));

        $this->assertSame(1, $result['counts']['paid_pending']);
        $this->assertSame(2, $result['counts']['sync_findings']);
    }

    public function test_letter_prefixes_are_not_discarded_when_matching_order_numbers(): void
    {
        $result = (new OperationalDigestAnalyzer)->analyze([['id' => 1, 'name' => '#A1001', 'created_at' => '2026-10-06', 'financial_status' => 'paid', 'fulfillment_status' => null]], [['orderNumber' => 'B1001', 'orderStatus' => 'shipped']], [], 3, CarbonImmutable::parse('2026-10-07'));
        $this->assertSame(0, $result['counts']['sync_findings']);
    }

    public function test_operator_access_validation_escaping_and_saved_result_reuse(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $this->get(route('reports.operational-digest'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('reports.operational-digest'))->assertForbidden();
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->fakeSources($store);
        OperationalIssue::factory()->for($store)->create(['title' => '<script>alert(1)</script>']);
        $this->actingAs($operator)->post(route('reports.operational-digest.store'), ['start_date' => 'bad', 'end_date' => '2026-10-07', 'threshold' => 0])->assertSessionHasErrors(['start_date', 'threshold']);
        $this->post(route('reports.operational-digest.store'), $this->input())->assertSeeText('Operational Digest')->assertSeeText('Source coverage')->assertDontSee('<script>alert(1)</script>', false);
        $this->get(route('reports.operational-digest.result', $this->input()))->assertSeeText('Estimated SLA due within 24 hours');
        $this->assertDatabaseCount('report_runs', 1);
    }

    public function test_existing_digest_command_builds_a_fresh_snapshot_and_combines_sections_for_the_same_recipient(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        Notification::fake();
        $store = Store::factory()->create(['store_number' => '12', 'email_rules' => [
            'operational_digest' => ['mode' => 'digest', 'threshold' => 0, 'include_zero' => true, 'email' => 'ops@example.com'],
            'scan_test' => ['mode' => 'digest', 'threshold' => 1, 'include_zero' => false, 'email' => 'ops@example.com'],
        ]]);
        $store->runLogs()->create(['tool' => 'scan_test', 'rows_found' => 2]);
        $this->fakeSources($store);

        $this->artisan('reports:email-digest')->assertSuccessful();

        Notification::assertSentOnDemandTimes(ReportDigestNotification::class, 1);
        Notification::assertSentOnDemand(ReportDigestNotification::class, function (ReportDigestNotification $notice, array $channels, $notifiable): bool {
            return count($notice->sections) === 2 && $notice->sections[1]['summary']['counts']['paid_pending'] === 2 && str_contains($notice->sections[1]['snapshot_url'], '/saved-reports/') && $notifiable->routeNotificationFor('mail') === 'ops@example.com';
        });
        $run = $store->reportRuns()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame(['2026-09-08', '2026-10-07', 3], $run->arguments);
    }

    public function test_disabled_operational_email_does_not_fetch_or_queue_a_snapshot(): void
    {
        Notification::fake();
        Queue::fake([BuildOperationalDigestEmail::class]);
        Store::factory()->create(['email_rules' => ['operational_digest' => ['mode' => 'off', 'threshold' => 0, 'include_zero' => true, 'email' => 'ops@example.com']]]);
        $this->mock(ShopifyTransport::class)->shouldNotReceive('paginateGraphql');

        $this->artisan('reports:email-digest')->assertSuccessful();

        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('report_runs', 0);
    }

    public function test_turning_email_off_after_queueing_prevents_delivery_and_closes_the_pending_snapshot(): void
    {
        Notification::fake();
        $store = Store::factory()->create(['email_rules' => ['operational_digest' => ['mode' => 'off', 'threshold' => 0, 'include_zero' => true, 'email' => 'ops@example.com']]]);
        $run = app(QueuedReportRunner::class)->createRun($store, 'operational_digest', RunOperationalDigest::class, ['2026-10-01', '2026-10-07', 3], '2026-10-01', '2026-10-07');
        $this->mock(ShopifyTransport::class)->shouldNotReceive('paginateGraphql');

        BuildOperationalDigestEmail::dispatchSync($store->id, $run->id);

        Notification::assertNothingSent();
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_failed_digest_job_marks_only_its_own_pending_run(): void
    {
        $store = Store::factory()->create();
        $runner = app(QueuedReportRunner::class);
        $first = $runner->createRun($store, 'operational_digest', RunOperationalDigest::class, ['2026-10-01', '2026-10-07', 3], '2026-10-01', '2026-10-07');
        $second = $runner->createRun($store, 'operational_digest', RunOperationalDigest::class, ['2026-10-01', '2026-10-07', 3], '2026-10-01', '2026-10-07');

        (new BuildOperationalDigestEmail($store->id, $first->id))->failed(new RuntimeException('Timeout'));

        $this->assertSame('failed', $first->fresh()->status);
        $this->assertSame('queued', $second->fresh()->status);
    }

    public function test_mail_content_describes_counts_coverage_and_unverified_shipping_evidence(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $store = Store::factory()->create(['store_number' => '12']);
        $this->fakeSources($store);
        $result = app(RunOperationalDigest::class)->handle($store, '2026-10-01', '2026-10-07', 3);
        $notice = new ReportDigestNotification('Store', [['tool' => 'operational_digest', 'rows' => count($result->rows), 'summary' => $result->meta, 'snapshot_url' => 'https://example.test/saved-reports/1']]);

        $mail = $notice->toMail(new \stdClass);

        $this->assertContains('Paid / Shopify pending: 2', $mail->introLines);
        $this->assertContains('Shipping labels and ShipStation shipped status do not confirm physical carrier handover.', $mail->introLines);
        $this->assertSame('https://example.test/saved-reports/1', $mail->actionUrl);
    }

    public function test_snapshot_times_preserve_the_declared_timezone_in_bulgarian(): void
    {
        app()->setLocale('bg');
        config(['app.timezone' => 'Europe/Sofia']);

        $this->assertSame('7.10.2026 12:00', UiFormat::date('2026-10-07T12:00:00Z', true, 'UTC'));
    }

    public function test_admin_can_configure_digest_defaults_per_store(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        $input = ['slug' => $store->slug, 'label' => $store->label, 'shopify_store' => $store->shopify_store, 'delivery_watch_days' => 5, 'operational_digest_policy' => ['sla_days' => '5', 'lookback_days' => '60']];
        $this->actingAs($admin)->put(route('admin.stores.update', $store), $input)->assertSessionHas('status', 'Store updated.');
        $this->assertSame(['sla_days' => 5, 'lookback_days' => 60], $store->fresh()->operationalDigestPolicy());
        $input['operational_digest_policy']['lookback_days'] = 0;
        $this->put(route('admin.stores.update', $store), $input)->assertSessionHasErrors('operational_digest_policy.lookback_days');
    }

    /** @return array{start_date: string, end_date: string, threshold: int} */
    private function input(): array
    {
        return ['start_date' => '2026-10-01', 'end_date' => '2026-10-07', 'threshold' => 3];
    }

    private function fakeSources(Store $store, bool $foreignOnly = false): void
    {
        $nodes = [
            $this->node(1, '#1001', '2026-10-04T20:00:00Z'),
            $this->node(2, '#1002', '2026-10-03T10:00:00Z'),
            $this->node(3, '#1003', '2026-10-05T10:00:00Z', 'REFUNDED', 'UNFULFILLED', '2026-10-06T10:00:00Z'),
            $this->node(4, '#1004', '2026-10-03T10:00:00Z', 'PAID', 'FULFILLED'),
        ];
        $this->mock(ShopifyTransport::class)->shouldReceive('paginateGraphql')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), Mockery::type('string'), 'orders', Mockery::type('array'), 100)->andReturn(['edges' => array_map(fn (array $node): array => ['node' => $node], $nodes), 'pages' => 1, 'truncated' => false]);
        $ss = $foreignOnly ? [['orderNumber' => '1001', 'orderStatus' => 'shipped', 'advancedOptions' => ['storeId' => 999]], ['orderNumber' => '1002', 'orderStatus' => 'shipped']] : [['orderNumber' => '1001', 'orderStatus' => 'shipped', 'advancedOptions' => ['storeId' => 12]], ['orderNumber' => '1003', 'orderStatus' => 'awaiting_shipment', 'advancedOptions' => ['storeId' => 12]]];
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchAllOrders')->once()->andReturn($ss);
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($client);
    }

    /** @return array<string, mixed> */
    private function node(int $id, string $number, string $created, string $financial = 'PAID', string $fulfillment = 'UNFULFILLED', ?string $cancelled = null): array
    {
        return ['id' => 'gid://shopify/Order/'.$id, 'legacyResourceId' => $id, 'name' => $number, 'createdAt' => $created, 'cancelledAt' => $cancelled, 'displayFinancialStatus' => $financial, 'displayFulfillmentStatus' => $fulfillment];
    }
}
