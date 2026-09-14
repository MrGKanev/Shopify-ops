<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_sees_store_scoped_configuration_without_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create(['label' => 'Primary Store', 'shopify_access_token' => 'secret-token', 'email_rules' => ['run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => true, 'email' => 'ops@example.com']]]);
        $admin->stores()->attach($store);

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSeeText('Primary Store')->assertSeeText('Shopify')->assertSeeText('ShipStation')->assertSeeText('1 active rules')->assertSeeText('Login abuse is limited automatically')->assertDontSeeText('secret-token');
    }

    public function test_settings_is_admin_only(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->stores()->attach(Store::factory()->create());

        $this->actingAs($operator)->get('/admin/settings')->assertForbidden();
    }
}
