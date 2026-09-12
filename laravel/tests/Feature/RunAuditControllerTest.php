<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Jobs\RunAuditJob;
use App\Models\Store;
use App\Models\User;
use App\Notifications\AuditDiscordNotification;
use App\Notifications\AuditSlackNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunAuditControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_and_configuration(): void
    {
        $this->get('/reports/run-audit')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/run-audit')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/run-audit', ['start_date' => 'bad', 'end_date' => '2026-06-30'])->assertSessionHasErrors('start_date');
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/run-audit', $this->input())->assertOk()->assertSeeText('credentials are required');
    }

    public function test_it_runs_records_and_renders_a_safe_summary(): void
    {
        Notification::fake();
        config()->set('services.slack.notifications.webhook_url', 'https://hooks.slack.com/services/T/B/test');
        config()->set('services.discord.notifications.webhook_url', 'https://discord.com/api/webhooks/1/test');
        [$operator, $store] = $this->userWithStore(true);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchAllOrders')->twice()->with('2026-06-01', '2026-07-07')->andReturn([['orderNumber' => '1001']]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->twice()->andReturn($client);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('itemMismatchCandidates')->twice()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-06-01', '2026-06-30')->andReturn(['orders' => [
            ['name' => '#1001', 'financial_status' => 'paid', 'total_price' => 10],
            ['name' => '<script>', 'financial_status' => 'paid', 'total_price' => 20],
        ], 'pages' => 100, 'truncated' => true]);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->twice()->andReturn(['fulfillment_orders' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $this->actingAs($operator)->post('/reports/run-audit', $this->input())->assertOk()->assertSeeText('2 Shopify orders · 1 missing')->assertSeeText('data was truncated')->assertDontSee('<script>', false);
        $this->assertDatabaseHas('run_logs', ['store_id' => $store->getKey(), 'tool' => 'run_audit', 'status' => 'issues_found', 'rows_found' => 1]);
        $this->assertTrue($store->auditSnapshots()->where('tool', 'run_audit')->whereDate('report_date', now()->toDateString())->where('rows_found', 1)->exists());
        $this->actingAs($operator)->post('/reports/run-audit', $this->input())->assertOk();
        $this->assertSame(1, $store->auditSnapshots()->count());
        Notification::assertSentOnDemand(AuditSlackNotification::class);
        Notification::assertSentOnDemand(AuditDiscordNotification::class);
    }

    public function test_it_shows_the_ok_state_when_nothing_is_missing(): void
    {
        Notification::fake();
        [$operator, $store] = $this->userWithStore(true);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchAllOrders')->once()->andReturn([['orderNumber' => '1001']]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn($client);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('itemMismatchCandidates')->once()->andReturn(['orders' => [
            ['name' => '#1001', 'financial_status' => 'paid', 'total_price' => 10],
        ], 'pages' => 1, 'truncated' => false]);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->once()->andReturn(['fulfillment_orders' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $this->actingAs($operator)->post('/reports/run-audit', $this->input())->assertOk()->assertSeeText('Every eligible Shopify order was found in ShipStation.');
        $this->assertDatabaseHas('run_logs', ['store_id' => $store->getKey(), 'tool' => 'run_audit', 'status' => 'ok', 'rows_found' => 0]);
    }

    public function test_it_queues_the_same_audit_and_hides_failures(): void
    {
        Queue::fake();
        [$operator, $store] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/run-audit/queue', $this->input())->assertRedirect()->assertSessionHas('status', 'Audit queued.');
        Queue::assertPushed(RunAuditJob::class, fn (RunAuditJob $job): bool => $job->storeId === $store->getKey() && $job->endDate === '2026-06-30');

        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($operator)->post('/reports/run-audit', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
        $this->assertDatabaseHas('run_logs', ['store_id' => $store->getKey(), 'tool' => 'run_audit', 'status' => 'error', 'error' => 'Audit failed.']);
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'];
    }

    /** @return array{User,Store} */
    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
