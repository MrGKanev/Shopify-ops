<?php

namespace Tests\Feature;

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
}
