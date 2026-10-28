<?php

namespace Tests\Feature;

use App\Application\Auth\LoginThrottle;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActionLogInstrumentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ignoring_an_order_is_logged(): void
    {
        [$operator, $store] = $this->makeUserAndStore(true);

        $this->actingAs($operator)->post('/ignored-orders', ['order_number' => '1234', 'reason' => 'Test'])->assertRedirect();

        $this->assertLogged('ignore_order', $operator, ['order_number' => '1234', 'reason' => 'Test'], $store);
    }

    public function test_unignoring_an_order_is_logged(): void
    {
        [$operator, $store] = $this->makeUserAndStore(true);
        $ignored = $store->ignoredOrders()->create(['order_number' => '1234', 'ignored_at' => today()]);

        $this->actingAs($operator)->delete(route('ignored-orders.destroy', $ignored))->assertRedirect();

        $this->assertLogged('unignore_order', $operator, ['order_number' => '1234'], $store);
    }

    public function test_bulk_unignoring_orders_is_logged(): void
    {
        [$operator, $store] = $this->makeUserAndStore(true);
        $first = $store->ignoredOrders()->create(['order_number' => '1', 'ignored_at' => today()]);
        $second = $store->ignoredOrders()->create(['order_number' => '2', 'ignored_at' => today()]);

        $this->actingAs($operator)->delete(route('ignored-orders.bulk-destroy'), ['ids' => [$first->id, $second->id]])->assertRedirect();

        $this->assertLogged('bulk_unignore_orders', $operator, ['count' => 2], $store);
    }

    public function test_importing_ignore_csv_is_logged(): void
    {
        [$operator, $store] = $this->makeUserAndStore(true);
        $file = UploadedFile::fake()->createWithContent('orders.csv', "order_number\n1001\n1002\n");

        $this->actingAs($operator)->post('/ignored-orders/import', ['file' => $file, 'reason' => 'bulk'])->assertRedirect();

        $this->assertLogged('import_ignore_csv', $operator, ['count' => 2, 'reason' => 'bulk'], $store);
    }

    public function test_pushing_to_shipstation_is_logged(): void
    {
        [$operator, $store] = $this->makeUserAndStore(true);
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumber')->once()->andReturn([['id' => 42, 'name' => '#1001', 'total_price' => '10.00', 'shipping_address' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '123 Main Street', 'city' => 'Boston', 'province_code' => 'MA', 'zip' => '02101', 'country_code' => 'US']]]);
        $this->app->instance(ShopifyOrders::class, $shopify);
        Http::fake(['https://ssapi.shipstation.com/orders/createorder' => Http::response(['orderId' => 555, 'orderNumber' => '1001'])]);

        $this->actingAs($operator)->post(route('orders.push.store'), ['order_number' => '1001'])->assertRedirect();

        $this->assertLogged('push_to_shipstation', $operator, ['order_number' => '1001'], $store);
    }

    public function test_print_queue_add_remove_clear_are_logged(): void
    {
        [$operator, $store] = $this->makeUserAndStore(true);

        $this->actingAs($operator)->post('/print-queue', ['order_number' => 'ORD-001'])->assertRedirect();
        $this->assertLogged('pq_add', $operator, ['order_number' => 'ORD-001'], $store);

        $item = $store->printQueueItems()->firstOrFail();
        $this->actingAs($operator)->delete(route('print-queue.destroy', $item))->assertRedirect();
        $this->assertLogged('pq_remove', $operator, ['order_number' => 'ORD-001'], $store);

        $store->printQueueItems()->create(['order_number' => 'ORD-002']);
        $this->actingAs($operator)->delete(route('print-queue.clear'))->assertRedirect();
        $this->assertLogged('pq_clear', $operator, ['count' => 1], $store);
    }

    public function test_queueing_an_audit_is_logged(): void
    {
        Queue::fake();
        [$operator, $store] = $this->makeUserAndStore(true);

        $this->actingAs($operator)->post('/reports/run-audit/queue', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'])->assertRedirect();

        $this->assertLogged('queue_audit', $operator, ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'], $store);
    }

    public function test_saving_an_order_note_is_logged(): void
    {
        [$operator, $store] = $this->makeUserAndStore(true);
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('updateOrderNote')->once();
        $this->app->instance(ShopifyOrders::class, $shopify);

        $this->actingAs($operator)->post(route('orders.note.update'), ['order_id' => '123', 'order_number' => '1001', 'note' => 'hello'])->assertRedirect();

        $this->assertLogged('save_order_note', $operator, ['shopify_id' => '123', 'note_length' => 5], $store);
    }

    public function test_switching_stores_is_logged(): void
    {
        [$user, $store] = $this->makeUserAndStore(false);

        $this->actingAs($user)->post(route('stores.active', $store))->assertRedirect();

        $this->assertLogged('switch_store', $user, ['store_id' => $store->getKey()], $store);
    }

    public function test_unbanning_an_ip_is_logged(): void
    {
        [$admin] = $this->makeUserAndStore(true, admin: true);
        $throttle = app(LoginThrottle::class);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $throttle->recordFailureMessage('1.2.3.4');
        }

        $this->actingAs($admin)->delete(route('admin.banned-ips.destroy'), ['ip' => '1.2.3.4'])->assertRedirect();

        $this->assertLogged('unban_ip', $admin, ['ip' => '1.2.3.4']);
    }

    public function test_saving_slack_rules_is_logged(): void
    {
        [$admin] = $this->makeUserAndStore(true, admin: true);

        $this->actingAs($admin)->put('/admin/slack-rules', ['audit_enabled' => '1', 'audit_min_missing' => 0, 'scan_enabled' => '0', 'scan_min_rows' => 1])->assertRedirect();

        $this->assertLoggedDescription('save_slack_rules', $admin);
    }

    public function test_saving_email_rules_is_logged(): void
    {
        [$admin] = $this->makeUserAndStore(true, admin: true);

        $this->actingAs($admin)->put('/admin/email-rules', ['rules' => ['run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => '1', 'email' => 'ops@example.com']]])->assertRedirect();

        $this->assertLoggedDescription('save_email_rules', $admin);
    }

    public function test_flushing_cache_is_logged(): void
    {
        [$admin, $store] = $this->makeUserAndStore(true, admin: true);

        $this->actingAs($admin)->post(route('admin.cache.flush'))->assertRedirect();

        $this->assertLogged('flush_cache', $admin, ['store_id' => $store->getKey()], $store);
    }

    /** @param array<string, mixed> $expectedProperties */
    private function assertLogged(string $description, User $causer, array $expectedProperties, ?Store $subject = null): void
    {
        $activity = $this->assertLoggedDescription($description, $causer, $subject);
        foreach ($expectedProperties as $key => $value) {
            $this->assertSame($value, $activity->properties[$key] ?? null, "Property '{$key}' mismatch for '{$description}'.");
        }
    }

    private function assertLoggedDescription(string $description, User $causer, ?Store $subject = null): Activity
    {
        $activity = Activity::where('log_name', 'operator-actions')->where('description', $description)->latest('id')->first();
        $this->assertNotNull($activity, "Expected an activity log entry for '{$description}'.");
        $this->assertSame('operator-actions', $activity->log_name);
        $this->assertSame($causer->getMorphClass(), $activity->causer_type);
        $this->assertSame($causer->id, $activity->causer_id);
        if ($subject !== null) {
            $this->assertSame($subject->getMorphClass(), $activity->subject_type, "Subject type mismatch for '{$description}'.");
            $this->assertSame($subject->id, $activity->subject_id, "Subject mismatch for '{$description}'.");
        }
        $this->assertNoSecretProperties($activity->properties->toArray(), $description);

        return $activity;
    }

    /** @param array<array-key, mixed> $properties */
    private function assertNoSecretProperties(array $properties, string $description): void
    {
        foreach ($properties as $key => $value) {
            $this->assertDoesNotMatchRegularExpression('/pass(word)?|secret|token|api_?key|authorization|credential/i', (string) $key, "Property '{$key}' of '{$description}' looks like a secret.");
            if (is_array($value)) {
                $this->assertNoSecretProperties($value, $description);
            }
        }
    }

    /** @return array{User, Store} */
    private function makeUserAndStore(bool $operator = false, bool $admin = false): array
    {
        $user = $admin ? User::factory()->admin()->create() : ($operator ? User::factory()->operator()->create() : User::factory()->create());
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
