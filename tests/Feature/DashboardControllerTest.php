<?php

namespace Tests\Feature;

use App\Models\HealthIncident;
use App\Models\Store;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_selects_and_renders_the_users_first_store(): void
    {
        $user = User::factory()->operator()->create(['name' => 'Operations User']);
        $secondStore = Store::factory()->create(['label' => 'Zulu Store']);
        $firstStore = Store::factory()->create([
            'label' => 'Alpha Store',
            'shopify_store' => 'alpha-shop',
        ]);
        $user->stores()->attach([$secondStore->getKey(), $firstStore->getKey()]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSeeText('Alpha Store')
            ->assertSeeText('alpha-shop.myshopify.com')
            ->assertDontSeeText('Last 7 days')
            ->assertSessionHas('active_store_id', $firstStore->getKey());
        $this->assertSame(UserRole::Operator, $user->fresh()->role);
    }

    public function test_dashboard_forbids_a_user_without_store_access(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertForbidden();
    }

    public function test_dashboard_escapes_store_labels(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'label' => '<script>alert("store")</script>',
        ]);
        $user->stores()->attach($store);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee($store->label)
            ->assertDontSee($store->label, false);
    }

    public function test_dashboard_renders_store_scoped_operational_stats_and_action_queue(): void
    {
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $foreign = Store::factory()->create();
        $user->stores()->attach($store);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-09', 'start_date' => '2026-09-01', 'end_date' => '2026-09-09', 'rows_found' => 3, 'result' => ['missing' => []]]);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-10', 'start_date' => '2026-09-01', 'end_date' => '2026-09-10', 'rows_found' => 1, 'result' => ['missing' => [['name' => '<script>#1001', 'created_at' => '2026-09-08', 'email' => 'buyer@example.com', 'total_price' => 42.5]]]]);
        $store->ignoredOrders()->create(['order_number' => '1002', 'ignored_at' => today()]);
        $store->pushLogs()->create(['order_number' => '1003', 'shopify_id' => '1', 'pushed_at' => now()]);
        $foreign->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-10', 'start_date' => '2026-09-01', 'end_date' => '2026-09-10', 'rows_found' => 99, 'result' => ['missing' => [['name' => 'secret-order']]]]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSeeText('missing · 2026-09-10')->assertSeeText('was 3')->assertSeeText('4 total missing')->assertSeeText('buyer@example.com')->assertSeeText('42.50')->assertSeeText('Run Audit')->assertDontSee('<script>', false)->assertDontSeeText('secret-order');
    }

    public function test_dashboard_shows_cadence_resolution_stale_ignored_oldest_and_seven_day_chart(): void
    {
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $this->travelTo('2026-09-10');
        $user->stores()->attach($store);
        // Order #A appears missing on day 1, resolved by day 3 (2-day resolution).
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-01', 'start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'rows_found' => 1, 'result' => ['missing' => [['name' => '#A', 'created_at' => '2026-08-30', 'email' => 'a@example.com', 'total_price' => 10]]]]);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-03', 'start_date' => '2026-09-01', 'end_date' => '2026-09-03', 'rows_found' => 2, 'result' => ['missing' => [['name' => '#A', 'created_at' => '2026-08-30', 'email' => 'a@example.com', 'total_price' => 10], ['name' => '#C', 'created_at' => '2026-09-01', 'email' => 'c@example.com', 'total_price' => 30]]]]);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-05', 'start_date' => '2026-09-01', 'end_date' => '2026-09-05', 'rows_found' => 0, 'result' => ['missing' => []]]);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-10', 'start_date' => '2026-09-01', 'end_date' => '2026-09-10', 'rows_found' => 2, 'result' => ['missing' => [['name' => '#B', 'created_at' => '2026-09-08', 'email' => 'b@example.com', 'total_price' => 20], ['name' => '#C', 'created_at' => '2026-09-09', 'email' => 'c@example.com', 'total_price' => 30]]]]);
        $store->ignoredOrders()->create(['order_number' => 'old', 'ignored_at' => '2026-07-01']);
        $store->ignoredOrders()->create(['order_number' => 'recent', 'ignored_at' => '2026-09-09']);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertViewHas('staleIgnoredCount', 1);
        $response->assertViewHas('oldestMissingAge', 2);
        $response->assertViewHas('avgResolutionDays', 2.0);
        $this->assertSame([
            ['date' => '2026-09-04', 'missing' => null],
            ['date' => '2026-09-05', 'missing' => 0],
            ['date' => '2026-09-06', 'missing' => null],
            ['date' => '2026-09-07', 'missing' => null],
            ['date' => '2026-09-08', 'missing' => null],
            ['date' => '2026-09-09', 'missing' => null],
            ['date' => '2026-09-10', 'missing' => 2],
        ], $response->viewData('sevenDayChart')->all());
        $response->assertSeeText('Last 7 days')->assertSee('2026-09-05: 0 missing')->assertSee('2026-09-06: No audit');
        $response->assertViewHas('auditCadenceDays', fn ($days) => $days > 0);
        $response->assertViewHas('auditsLast30Days', 4);
        $response->assertViewHas('clearAuditRate', 25.0);
        $response->assertViewHas('recurringMissingCount', 1);
    }

    public function test_dashboard_shows_days_without_audits_when_the_last_snapshot_is_older_than_a_week(): void
    {
        $this->travelTo('2026-09-10');
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-01', 'start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'rows_found' => 3, 'result' => ['missing' => []]]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSeeText('Last 7 days');
        $this->assertSame([
            ['date' => '2026-09-04', 'missing' => null],
            ['date' => '2026-09-05', 'missing' => null],
            ['date' => '2026-09-06', 'missing' => null],
            ['date' => '2026-09-07', 'missing' => null],
            ['date' => '2026-09-08', 'missing' => null],
            ['date' => '2026-09-09', 'missing' => null],
            ['date' => '2026-09-10', 'missing' => null],
        ], $response->viewData('sevenDayChart')->all());
    }

    public function test_sidebar_warns_when_shopify_or_shipstation_is_down(): void
    {
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);
        HealthIncident::factory()->create(['check_name' => 'ShopifyApiHealth', 'check_label' => 'Shopify Api Health']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSeeText('Shopify or ShipStation is having issues');
    }

    public function test_sidebar_hides_the_warning_once_resolved(): void
    {
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);
        HealthIncident::factory()->resolved()->create(['check_name' => 'ShipStationApiHealth', 'check_label' => 'Ship Station Api Health']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSeeText('Shopify or ShipStation is having issues');
    }

    public function test_admin_sees_a_cache_flush_button_operator_does_not(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        $operator = User::factory()->operator()->create();
        $operator->stores()->attach($store);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee(route('admin.cache.flush'), false);
        $this->actingAs($operator)->get(route('dashboard'))->assertOk()->assertDontSee(route('admin.cache.flush'), false);
    }
}
